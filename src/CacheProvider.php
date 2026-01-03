<?php

namespace CanvasApiLibrary\RedisCacheProvider;

use CanvasApiLibrary\Caching\AccessAware\Interfaces\CacheProviderInterface;
use CanvasApiLibrary\Caching\AccessAware\Interfaces\CacheResult;
use CanvasApiLibrary\RedisCacheProvider\Lua;
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
                0,
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
        $this->scriptShas['addPermsBackprop'] = $this->redis->script('load', Lua\AddPermissionBackpropagation::script());
        $this->scriptShas['getIfPermitted'] = $this->redis->script('load', Lua\GetIfPermitted::script());
        $this->scriptShas['getCollection'] = $this->redis->script('load', Lua\GetCollection::script());
        $this->scriptShas['getCollectionDominance'] = $this->redis->script('load', Lua\GetCollectionDominance::script());
        $this->scriptShas['getCollectionVariants'] = $this->redis->script('load', Lua\GetCollectionVariants::script());
    }
//TODO check lua scripts for correctness
}