<?php

namespace CanvasApiLibrary\RedisCacheProvider;

use CanvasApiLibrary\Caching\AccessAware\Interfaces\CacheProviderInterface;
use CanvasApiLibrary\Caching\AccessAware\Interfaces\CacheResult;
use InvalidArgumentException;
use Predis\Client;

/**
 * @phpstan-type Permission string
 * @phpstan-type ContextFilter string
 * @phpstan-type PermissionType string
 * @implements CacheProviderInterface<Permission, ContextFilter, PermissionType>
 */
class CacheProvider implements CacheProviderInterface{
    private const ITEM_PREFIX = 'item:';
    private const CLIENT_PREFIX = 'client:';
    private const COLLECTION_PREFIX = 'collection:';
    private const BACKPROP_UNION_TYPE = 'union';

    /** @var array<string,string> */
    private array $scriptShas = [];

    public function __construct(private readonly Client $redis, private readonly PermissionHandler $permissionHandler){
        $this->loadLuaScripts();
    }
    /**
     * Sets the value of an item in the cache
     * Permission bound cache operation.
     * @param string $itemKey Cache key for the item, must uniquely identify this item as an individual resource.
     * @param mixed $value Value to cache. Must be the actual value, not a Result-type wrapper / value
     * @param int $ttl Time to keep in cache in seconds.
     * @param string $clientID Id of the current cache client.
     * @param Permission[] $permissionsRequired String indicating the permission required to access this item. Items may have multiple permissions, store all of them, only one is required to access it.
     * @return void
     */
    public function set(string $itemKey, mixed $value, int $ttl, string $clientID, mixed ...$permissionsRequired){
        $serialized = serialize($value);

        $valueKey = $this->valueKey($itemKey);
        $permsKey = $this->permsKey($itemKey);
        $backpropTypesKey = $this->backpropTypesKey($itemKey);

        $permTypes = array_map([$this, 'permissionType'], $permissionsRequired);

        $this->redis->set($valueKey, $serialized);
        if ($ttl > 0) {
            $this->redis->expire($valueKey, $ttl);
        }

        $this->evalAddPermissionsWithBackprop($itemKey, $permissionsRequired, $permTypes, $backpropTypesKey);

        if ($ttl > 0) {
            $this->redis->expire($permsKey, $ttl);
        }
    }

    /**
     * Tries to retrieve a value by key from the cache. Will do so if the client has any matching permission for any of the permissions of this item.
     * Permission bound cache operation.
     * @param string $clientID Id by which to identify this client.
     * @param string $key Key in the cache
     * @return CacheResult
     */
    public function get(string $clientID, string $key) : CacheResult{
        $result = $this->evalGetIfPermitted($clientID, $key);

        if ($result === null) {
            return new CacheResult(null, false);
        }

        return new CacheResult(unserialize($result), true);
    }

    /**
     * Gets an unprotected value by key.
     * @param string $key
     * @return CacheResult
     */
    public function getUnprotected(string $key) : CacheResult{
        $value = $this->redis->get($this->valueKey($key));

        if ($value === null) {
            return new CacheResult(null, false);
        }

        return new CacheResult(unserialize($value), true);
    }

    /**
     * Sets a value in the cache without permissions.
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     * @return void
     */
    public function setUnprotected(string $key, mixed $value, int $ttl) : void{
        $serialized = serialize($value);
        $valueKey = $this->valueKey($key);

        $this->redis->set($valueKey, $serialized);
        if ($ttl > 0) {
            $this->redis->expire($valueKey, $ttl);
        }
    }


    /**
     * Saves a set of item keys for a given client and a given collection key.
     * Results are saved through dominance matching of permission types.
     * Must save child items to cache first.
     * 
     * Assumes client has all the permissions possible to the given collection, this is the responsibility of the user of this library
     * 
     * Client bound cache operation.
     * @param string $clientID The ID of this client.
     * @param string $collectionKey The key by which the collection is to be stored.
     * @param array $itemKeys The list of item keys which belong to this collection.
     * @param int $ttl Time to keep in cache in seconds.
     * @param ContextFilter[] $itemPermissionContextFilter Allowed context filters for this collection. Any collection may only have ONE filter of any TYPE. 
     * Adding a filter of the same type of a different context throws an exception.
     * @return void
     */
    public function setCollection(string $clientID, string $collectionKey, array $itemKeys, int $ttl, mixed ...$itemPermissionContextFilters){
        $itemsKey = $this->collectionItemsKey($collectionKey);
        $filtersKey = $this->collectionFiltersKey($collectionKey);

        if (!empty($itemPermissionContextFilters)) {
            $this->assertUniqueContextFilters($filtersKey, $itemPermissionContextFilters);
        }

        if (!empty($itemKeys)) {
            $this->redis->sadd($itemsKey, $itemKeys);
        }

        foreach ($itemPermissionContextFilters as $filter) {
            $type = $this->contextFilterType($filter);
            $this->redis->hset($filtersKey, $type, $filter);
        }

        if ($ttl > 0) {
            $this->redis->expire($itemsKey, $ttl);
            $this->redis->expire($filtersKey, $ttl);
        }
    }

    /**
     * Sets an item as a target for permission backpropagation. Multiple permission types may be set, but the target may not be changed for any collection.
     * All permissions that get added to the child items in this colleciton, which fall within the PermissionType,
     * get added to the permission list of the target item.
     * This way it is possible to propagate back permissions of dependent items back to their origin.
     * 
     * Backpropagation should only be enabled on collections where the permission to view the target (source) model 
     * is dependent on being allowed to view at least one of the child (dependent) models.
     * 
     * Example:
     * A group is a domain bound model. It has users. Users may have a domain-course-user bound permission, or a domain-user permission.
     * If a client has permissions to view a specific user, whether through a domain-course-user, or domain-user,
     * they also have access to any group this user belongs to. If backpropagation is set to the domain-cours-user permission for this group,
     * the group item will gain all the permissions for all users that fall under it, even when permissions are added to child items late, 
     * thus anyone with that user permission can also see the cached group.
     * @param string $collectionKey The key with which the cached collection is stored in the database
     * @param PermissionType $permissionType The type of permission to backpropagate.
     * @param string $target The target which gains the permissions.
     * @return void
     */
    public function setBackpropagation(string $collectionKey, mixed $permissionType, string $target){
        $itemsKey = $this->collectionItemsKey($collectionKey);
        $itemKeys = $this->redis->smembers($itemsKey);

        foreach ($itemKeys as $itemKey) {
            $this->addBackpropTarget($itemKey, (string)$permissionType, $target);
        }
    }

    /**
     * Configures all the items with the given keys to share permissions. Used for synching permissions of different model form of the same instance.
     * @param string[] $keys
     * @return void
     */
    public function setPermissionUnion(string ...$keys){
        $uniqueKeys = array_values(array_unique($keys));

        foreach ($uniqueKeys as $source) {
            foreach ($uniqueKeys as $target) {
                if ($source === $target) {
                    continue;
                }
                $this->addBackpropTarget($source, self::BACKPROP_UNION_TYPE, $target);
            }
        }
    }

    
    /**
     * Tries to retrieve a cached collection items for this client. 
     * If successfull, returns an array of the actual items.
     * 
     * Finding cache hits is done through dominance matching, then filtering.
     * 
     * Collections can only have one context filter per permission type. 
     * IE, a collection (and its items) in canvas can only belong to 1 course, in addition to globally in 1 domain.
     * All collections have a filter to which subgroup they belong to. 
     * IE, an item that is bound to a user can be accessed through a domain-user permission token,
     * or a domain-course-user token.
     * 
     * The caching wrapper carries responsibility for ensuring all permissions that can be known of a client are known in the relevant context.
     * 
     * Then, within a context, if the client's permissions in that context are a subset of the known permissions in that collection,
     * then the collection can be returned through a filter. This also works for items which can be accessed through several context types, 
     * but not for items accesible through multiple competing contexts of the same type. For the latter case (such as users),
     * seperate collections must be set up per context, such as a seperate collection for all users in a course.
     * 
     * Client bound cache operation.
     * @param string $clientID Id by which to identify this client.
     * @param string $collectionKey Key of the collection.
     * @return CacheResult Hit with an array of the actual cached data of the items cached if found. 
     * Miss (empty) otherwise.
     */
    public function getCollection(string $clientID, string $collectionKey): CacheResult{
                $values = $this->evalGetCollection($clientID, $collectionKey);

                if ($values === null) {
                        return new CacheResult(null, false);
                }

                $items = array_map('unserialize', $values);

                return new CacheResult($items, true);
    }

    /**
     * Runs the Lua script that adds permissions to an item and propagates them along typed backprop targets.
     * @param string[] $permissions
     * @param string[] $permissionTypes
     */
    private function evalAddPermissionsWithBackprop(string $itemKey, array $permissions, array $permissionTypes, string $backpropTypesKey): void{
        //TODO backpropTypesKey is unused?
        $permCount = count($permissions);
        if ($permCount === 0) {
                return;
        }

        $args = [];
        $args[] = $itemKey;
        $args[] = (string)$permCount;
        foreach ($permissions as $perm) {
                $args[] = (string)$perm;
        }
        foreach ($permissionTypes as $type) {
                $args[] = (string)$type;
        }
        $args[] = self::ITEM_PREFIX;

        $this->redis->evalsha(
                $this->scriptShas['addPermsBackprop'],
                0, //todo What is this?
                ...$args
        );
    }

    private function evalGetIfPermitted(string $clientID, string $itemKey): mixed{
        $clientPermsKey = $this->clientPermsKey($clientID);
        $itemPermsKey = $this->permsKey($itemKey);
        $itemValueKey = $this->valueKey($itemKey);

        $result = $this->redis->evalsha(
                $this->scriptShas['getIfPermitted'],
                3,
                $clientPermsKey,
                $itemPermsKey,
                $itemValueKey
        );

        if (!is_array($result) || count($result) !== 2) {
                return null;
        }

        $authorized = (bool)$result[0];
        $value = $result[1];

        return $authorized ? $value : null;
    }

    /**
     * @return string[]|null
     */
    private function evalGetCollection(string $clientID, string $collectionKey): ?array{
        $clientPermsKey = $this->clientPermsKey($clientID);
        $itemsKey = $this->collectionItemsKey($collectionKey);
        $filtersKey = $this->collectionFiltersKey($collectionKey);

        $values = $this->redis->evalsha(
                $this->scriptShas['getCollection'],
                3,
                $clientPermsKey,
                $itemsKey,
                $filtersKey,
                self::ITEM_PREFIX
        );

        if ($values === null) {
                return null;
        }

        if (!is_array($values)) {
                return null;
        }

        return array_values($values);
    }

    private function addBackpropTarget(string $itemKey, string $type, string $target): void{
        $typesKey = $this->backpropTypesKey($itemKey);
        $targetsKey = $this->backpropTargetsKey($itemKey, $type);

        $this->redis->sadd($typesKey, [$type]);
        $this->redis->sadd($targetsKey, [$target]);
    }

    /**
     * @param string $filtersKey
     * @param string[] $filters
     */
    private function assertUniqueContextFilters(string $filtersKey, array $filters): void{
            $existing = $this->redis->hgetall($filtersKey);

            foreach ($filters as $filter) {
                    $type = $this->contextFilterType($filter);
                    if (isset($existing[$type]) && $existing[$type] !== $filter) {
                            throw new InvalidArgumentException('Duplicate context filter type with different context: ' . $type);
                    }
            }
    }

    private function valueKey(string $itemKey): string{
            return self::ITEM_PREFIX . $itemKey . ':value';
    }

    private function permsKey(string $itemKey): string{
            return self::ITEM_PREFIX . $itemKey . ':perms';
    }

    private function backpropTypesKey(string $itemKey): string{
            return self::ITEM_PREFIX . $itemKey . ':backprop:types';
    }

    private function backpropTargetsKey(string $itemKey, string $type): string{
            return self::ITEM_PREFIX . $itemKey . ':backprop:' . $type;
    }

    private function clientPermsKey(string $clientID): string{
            return self::CLIENT_PREFIX . $clientID . ':perms';
    }

    private function collectionItemsKey(string $collectionKey): string{
            return self::COLLECTION_PREFIX . $collectionKey . ':items';
    }

    private function collectionFiltersKey(string $collectionKey): string{
            return self::COLLECTION_PREFIX . $collectionKey . ':filters';
    }

    private function permissionType(string $permission): string{
            return $this->typeFromToken($permission);
    }

    private function contextFilterType(string $filter): string{
            return $this->typeFromToken($filter);
    }

    private function typeFromToken(string $token): string{
            if ($token === '') {
                    return '';
            }

            if (str_starts_with($token, 'global')) {
                    return 'global';
            }

            $parts = explode(';', $token);
            $typeParts = [];

            $count = count($parts);
            for ($i = 0; $i < $count; $i += 2) {
                    $label = $parts[$i] ?? '';
                    if ($label === '' || $label === '*') {
                            break;
                    }

                    $typeParts[] = $label;

                    $nextIndex = $i + 1;
                    $valuePart = $parts[$nextIndex] ?? '';
                    if ($valuePart === '*' || $valuePart === '') {
                            break;
                    }
            }

            return implode(';', $typeParts);
    }

    private function loadLuaScripts(): void{
            $this->scriptShas['addPermsBackprop'] = $this->redis->script('load', $this->luaAddPermsBackprop());
            $this->scriptShas['getIfPermitted'] = $this->redis->script('load', $this->luaGetIfPermitted());
            $this->scriptShas['getCollection'] = $this->redis->script('load', $this->luaGetCollection());
    }

        private function luaAddPermsBackprop(): string{
                return <<<LUA
local rootItemKey = ARGV[1]
local permCount = tonumber(ARGV[2])
local perms = {}
local types = {}

--[[
Args: string rootItemKey, int permCount, string permissions[0...permCount], string types[0...permCount], string itemPrefix
]]--

for i = 1, permCount do
    perms[i] = ARGV[2 + i]
end

for i = 1, permCount do
    types[i] = ARGV[2 + permCount + i]
end

local itemPrefix = ARGV[2 + (permCount * 2) + 1]

local function permsKey(itemKey)
    return itemPrefix .. itemKey .. ':perms'
end

local function backpropTypesKey(itemKey)
    return itemPrefix .. itemKey .. ':backprop:types'
end

local function backpropTargetsKey(itemKey, t)
    return itemPrefix .. itemKey .. ':backprop:' .. t
end

local queue = {rootItemKey}
local visited = {}
visited[rootItemKey] = true

while #queue > 0 do
    local itemKey = table.remove(queue)

    local pk = permsKey(itemKey)
    for i = 1, permCount do
        redis.call('SADD', pk, perms[i])
    end

    local tKey = backpropTypesKey(itemKey)
    local bpTypes = redis.call('SMEMBERS', tKey)

    for _, t in ipairs(bpTypes) do
        --For each target, check which permissions to propagate

        local targetsKey = backpropTargetsKey(itemKey, t)
        local targets = redis.call('SMEMBERS', targetsKey)

        local shouldPropagate = {}

        if t == 'union' then
            for i = 1, permCount do
                shouldPropagate[i] = true
            end
        else
            for i = 1, permCount do
                if types[i] == t then
                    shouldPropagate[i] = true
                end
            end
        end

        for _, target in ipairs(targets) do
            if not visited[target] then
                visited[target] = true
                table.insert(queue, target)
            end

            local targetPermsKey = permsKey(target)
            for i = 1, permCount do
                if shouldPropagate[i] then
                    redis.call('SADD', targetPermsKey, perms[i])
                end
            end
        end
    end
end

return 1
LUA;
        }

        private function luaGetIfPermitted(): string{
                return <<<LUA
local clientPermsKey = KEYS[1]
local itemPermsKey = KEYS[2]
local valueKey = KEYS[3]

--Intersect the known client permissions with the item permissions, if at least one matches, access is allowed
local inter = redis.call('SINTER', clientPermsKey, itemPermsKey)

if #inter > 0 then
    local value = redis.call('GET', valueKey)
    return {1, value}
end

return {0, nil}
LUA;
        }

        private function luaGetCollection(): string{
                return <<<LUA
local clientPermsKey = KEYS[1]
local itemsKey = KEYS[2]
local filtersKey = KEYS[3]
local itemPrefix = ARGV[1]

--If no permissions for client, return empty array
local clientPerms = redis.call('SMEMBERS', clientPermsKey)
if #clientPerms == 0 then
    return {}
end

local items = redis.call('SMEMBERS', itemsKey)
local results = {}

for _, itemKey in ipairs(items) do
    local permsKey = itemPrefix .. itemKey .. ':perms'
    local valueKey = itemPrefix .. itemKey .. ':value'

    local inter = redis.call('SINTER', permsKey, clientPermsKey)
    if #inter > 0 then
        local v = redis.call('GET', valueKey)
        if v then
            table.insert(results, v)
        end
    end
end

return results
LUA;
        }
}