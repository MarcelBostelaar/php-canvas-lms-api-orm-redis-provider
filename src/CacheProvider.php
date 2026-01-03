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

        $permTypes = array_map([$this->permissionHandler, 'typeFromPermission'], $permissionsRequired);

        $this->redis->set($valueKey, $serialized);
        if ($ttl > 0) {
            $this->redis->expire($valueKey, $ttl);
        }

        $this->evalAddPermissionsWithBackprop($itemKey, $permissionsRequired, $permTypes);

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
     * Each collection is stored as a variant.
     * Multiple different subsets may exist for the same collection key (one per client with different permissions).
     * Each variant stores items and the client's permissions filtered to the context.
     * 
     * Assumes client has all the permissions possible to the given collection, this is the responsibility of the user of this library
     * 
     * Client bound cache operation.
     * @param string $clientID The ID of this client.
     * @param string $collectionKey The key by which the collection is to be stored.
     * @param array $itemKeys The list of item keys which belong to this collection.
     * @param int $ttl Time to keep in cache in seconds.
     * @param ContextFilter $itemPermissionContextFilter The context filter for this collection.
     * @return void
     */
    public function setCollection(string $clientID, string $collectionKey, array $itemKeys, int $ttl, mixed $itemPermissionContextFilter){
        //Unique id per call
        $variantID = $this->generateVariantID();
        $itemsKey = $this->collectionItemsKeyForVariant($collectionKey, $variantID);
        $permsKey = $this->collectionPermsKeyForVariant($collectionKey, $variantID);
        $countKey = $this->collectionVariantCountKey($collectionKey, $variantID);

        $filterKey = $this->collectionFilterKey($collectionKey);
        //Set of all variants keys
        $variantsSetKey = $this->collectionVariantsSetKey($collectionKey);

        if (!empty($itemKeys)) {
            $this->redis->sadd($itemsKey, $itemKeys);
        }

        $this->redis->set($filterKey, $itemPermissionContextFilter);
        $this->redis->sadd($variantsSetKey, [$variantID]);

        $this->saveKnownPermissionsInContext($permsKey, $clientID, $itemPermissionContextFilter);
        
        $permsCount = $this->redis->scard($permsKey);
        $this->redis->set($countKey, (string)$permsCount);

        if ($ttl > 0) {
            $this->redis->expire($itemsKey, $ttl);
            $this->redis->expire($permsKey, $ttl);
            $this->redis->expire($countKey, $ttl);
            //filter key is shared, do not expire. Assume user does not provide conflicting filters for the same collection.
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
        $redisCollectionKey = $this->collectionKey($collectionKey);
        $itemKeys = $this->redis->smembers($redisCollectionKey);

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
     * Finding cache hits is done through dominance matching across variants.
     * For each stored variant, checks if the client's filtered permissions are a subset.
     * If found, returns items filtered to the client's actual permissions.
     * Variants are checked largest-to-smallest (by permission count) for best match.
     * 
     * Client bound cache operation.
     * @param string $clientID Id by which to identify this client.
     * @param string $collectionKey Key of the collection.
     * @return CacheResult Hit with an array of the actual cached data of the items cached if found. 
     * Miss (empty) otherwise.
     */
    public function getCollection(string $clientID, string $collectionKey): CacheResult{
        $clientPermsKey = $this->clientPermsKey($clientID);
        
        $result = $this->evalGetCollectionVariants($clientID, $collectionKey, $clientPermsKey);
        
        if ($result === null || !is_array($result)) {
            return new CacheResult(null, false);
        }

        $items = array_map('unserialize', $result);
        return new CacheResult($items, true);
    }

    /**
     * @param string $permsKey
     * @param string $clientID
     * @param string $type
     */
    private function saveKnownPermissionsInContext(string $permsKey, string $clientID, string $contextFilter): void{
        $clientPermsKey = $this->clientPermsKey($clientID);
        $clientPerms = $this->redis->smembers($clientPermsKey);
        
        $filteredPerms = $this->permissionHandler::filterPermissionsToContext($contextFilter, $clientPerms);
        $this->redis->sadd($permsKey, $filteredPerms);
    }

    /**
     * Runs the Lua script that adds permissions to an item and propagates them along typed backprop targets.
     * @param string[] $permissions
     * @param string[] $permissionTypes
     */
    private function evalAddPermissionsWithBackprop(string $itemKey, array $permissions, array $permissionTypes): void{
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
     * Evaluates all collection variants for dominance matching.
     * Finds the best matching variant where client's filtered perms are a subset.
     * @return string[]|null
     */
    private function evalGetCollectionVariants(string $clientID, string $collectionKey, string $clientPermsKey): ?array{
        $result = $this->redis->evalsha(
            $this->scriptShas['getCollectionVariants'],
            2,
            $clientPermsKey,
            $collectionKey,
            self::ITEM_PREFIX,
            self::COLLECTION_PREFIX
        );

        if ($result === null || !is_array($result)) {
            return null;
        }

        return array_values($result);
    }

    private function addBackpropTarget(string $itemKey, string $type, string $target): void{
        $targetCollectionKey = $this->backpropTargetCollectionKey($itemKey, $type);
        $this->redis->sadd($targetCollectionKey, [$target]);
    }

    private function valueKey(string $itemKey): string{
            return self::ITEM_PREFIX . $itemKey . ':value';
    }

    private function permsKey(string $itemKey): string{
            return self::ITEM_PREFIX . $itemKey . ':perms';
    }

    private function backpropTargetCollectionKey(string $itemKey, string $type): string{
            return self::ITEM_PREFIX . $itemKey . ':backprop:' . $type;
    }

    private function clientPermsKey(string $clientID): string{
            return self::CLIENT_PREFIX . $clientID . ':perms';
    }

    private function collectionKey(string $collectionKey): string{
        return self::COLLECTION_PREFIX . $collectionKey . ':items';
    }

    private function collectionVariantsSetKey(string $collectionKey): string{
        return self::COLLECTION_PREFIX . $collectionKey . ':variants';
    }

    private function collectionItemsKeyForVariant(string $collectionKey, string $variantID): string{
        return self::COLLECTION_PREFIX . $collectionKey . ':' . $variantID . ':items';
    }

    private function collectionFilterKey(string $collectionKey): string{
        return self::COLLECTION_PREFIX . $collectionKey . ':filter';
    }

    private function collectionPermsKeyForVariant(string $collectionKey, string $variantID): string{
        return self::COLLECTION_PREFIX . $collectionKey . ':' . $variantID . ':perms';
    }

    private function collectionVariantCountKey(string $collectionKey, string $variantID): string{
        return self::COLLECTION_PREFIX . $collectionKey . ':' . $variantID . ':count';
    }

    private function generateVariantID(): string{
        return \uniqid('var_', true);
    }

    private function loadLuaScripts(): void{
        $this->scriptShas['addPermsBackprop'] = $this->redis->script('load', $this->luaAddPermsBackprop());
        $this->scriptShas['getIfPermitted'] = $this->redis->script('load', $this->luaGetIfPermitted());
        $this->scriptShas['getCollection'] = $this->redis->script('load', $this->luaGetCollection());
        $this->scriptShas['getCollectionDominance'] = $this->redis->script('load', $this->luaGetCollectionDominance());
        $this->scriptShas['getCollectionVariants'] = $this->redis->script('load', $this->luaGetCollectionVariants());
    }
//TODO check lua scripts for correctness
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

    -- Find all backprop target keys using pattern matching
    local pattern = itemPrefix .. itemKey .. ':backprop:*'
    local backpropKeys = redis.call('KEYS', pattern)

    for _, targetsKey in ipairs(backpropKeys) do
        -- Extract type from key (format: itemPrefix..itemKey..':backprop:'..type)
        local t = string.match(targetsKey, ':backprop:(.+)$')
        if not t then
            goto continue_backprop
        end
        
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
        
        ::continue_backprop::
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

        private function luaGetCollectionDominance(): string{
                return <<<LUA
local clientPermsKey = KEYS[1]
local itemsKey = KEYS[2]
local collectionPermsKey = KEYS[3]
local itemPrefix = ARGV[1]

local clientPerms = redis.call('SMEMBERS', clientPermsKey)
if #clientPerms == 0 then
    return {}
end

local knownPerms = redis.call('SMEMBERS', collectionPermsKey)

local function isSubset(clientPerms, knownPerms)
    for _, clientPerm in ipairs(clientPerms) do
        local found = false
        for _, knownPerm in ipairs(knownPerms) do
            if clientPerm == knownPerm then
                found = true
                break
            end
        end
        if not found then
            return false
        end
    end
    return true
end

if not isSubset(clientPerms, knownPerms) then
    return {}
end

local items = redis.call('SMEMBERS', itemsKey)
local results = {}

for _, itemKey in ipairs(items) do
    local valueKey = itemPrefix .. itemKey .. ':value'
    local v = redis.call('GET', valueKey)
    if v then
        table.insert(results, v)
    end
end

return results
LUA;
        }

        private function luaGetCollectionVariants(): string{
                return <<<LUA
local clientPermsKey = KEYS[1]
local collectionKey = KEYS[2]
local itemPrefix = ARGV[1]
local collPrefix = ARGV[2]

local clientPerms = redis.call('SMEMBERS', clientPermsKey)
if #clientPerms == 0 then
    return {}
end

local variantsSetKey = collPrefix .. collectionKey .. ':variants'
local variants = redis.call('SMEMBERS', variantsSetKey)

if #variants == 0 then
    return {}
end

-- Sort variants by permission count (largest first)
local variantData = {}
for _, variantID in ipairs(variants) do
    local countKey = collPrefix .. collectionKey .. ':' .. variantID .. ':count'
    local count = tonumber(redis.call('GET', countKey) or '-1')
    if count >= 0 then --Expired variants will be skipped
        table.insert(variantData, {id = variantID, count = count})
    end
end

table.sort(variantData, function(a, b) return a.count > b.count end)

-- Check each variant in order (largest first)
for _, variant in ipairs(variantData) do
    local variantID = variant.id
    local variantPermsKey = collPrefix .. collectionKey .. ':' .. variantID .. ':perms'
    local variantItemsKey = collPrefix .. collectionKey .. ':' .. variantID .. ':items'
    
    local variantPerms = redis.call('SMEMBERS', variantPermsKey)
    
    -- Check if clientPerms is subset of variantPerms
    local isSubset = true
    for _, clientPerm in ipairs(clientPerms) do
        local found = false
        for _, variantPerm in ipairs(variantPerms) do
            if clientPerm == variantPerm then
                found = true
                break
            end
        end
        if not found then
            isSubset = false
            break
        end
    end
    
    -- If clientPerms is superset (not subset and all variant perms in client), skip
    if not isSubset then
        local isSupersetOrDisjoint = true
        for _, variantPerm in ipairs(variantPerms) do
            local found = false
            for _, clientPerm in ipairs(clientPerms) do
                if variantPerm == clientPerm then
                    found = true
                    break
                end
            end
            if not found then
                isSupersetOrDisjoint = false
                break
            end
        end
        
        if isSupersetOrDisjoint and #variantPerms > 0 then
            goto continue
        end
    end
    
    -- If we reach here and not subset, it's disjoint, try next
    if not isSubset then
        goto continue
    end
    
    -- clientPerms is subset of variantPerms, return items filtered to client perms
    local variantItems = redis.call('SMEMBERS', variantItemsKey)
    local results = {}
    
    for _, itemKey in ipairs(variantItems) do
        local itemPermsKey = itemPrefix .. itemKey .. ':perms'
        local itemValueKey = itemPrefix .. itemKey .. ':value'
        
        local inter = redis.call('SINTER', itemPermsKey, clientPermsKey)
        if #inter > 0 then
            local v = redis.call('GET', itemValueKey)
            if v then
                table.insert(results, v)
            end
        end
    end
    
    if #results > 0 then
        return results
    end
    
    ::continue::
end

return {}
LUA;
        }
}