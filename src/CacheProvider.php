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

    private Lua\AddPermissionThenBackpropagation $addPermissionBackpropagationScript;
    private Lua\GetIfPermitted $getIfPermittedScript;
    private Lua\GetCollectionVariants $getCollectionVariantsScript;
    private Lua\SaveFilteredKnownClientPermissionsToKey $saveFilteredKnownClientPermissionsScript;

    public function __construct(private readonly Client $redis, private readonly PermissionHandler $permissionHandler){
        $this->addPermissionBackpropagationScript = new Lua\AddPermissionThenBackpropagation($this->redis, $this->permissionHandler);
        $this->getIfPermittedScript = new Lua\GetIfPermitted($this->redis);
        $this->getCollectionVariantsScript = new Lua\GetCollectionVariants($this->redis);
        $this->saveFilteredKnownClientPermissionsScript = new Lua\SaveFilteredKnownClientPermissionsToKey($this->redis, $this->permissionHandler);
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

        $valueKey = Utility::valueKey($itemKey);
        $permsKey = Utility::permsKey($itemKey);

        $this->redis->set($valueKey, $serialized);
        if ($ttl > 0) {
            $this->redis->expire($valueKey, $ttl);
        }

        $this->addPermissionBackpropagationScript->run($itemKey, $permissionsRequired, $clientID);

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
        $result = $this->getIfPermittedScript->run($clientID, $key);

        if ($result === null) {
            return new CacheResult(null, false);
        }

        return new CacheResult(unserialize($result), true);
    }

    /**
     * Sets a value privately for only the given client
     * @param string $itemKey
     * @param mixed $value
     * @param int $ttl
     * @param string $clientID
     * @return void
     */
    public function setPrivate(string $itemKey, mixed $value, int $ttl, string $clientID): void{
        $serialized = serialize($value);
        $privateKey = Utility::privateKey($itemKey, $clientID);

        $this->redis->set($privateKey, $serialized);
        if ($ttl > 0) {
            $this->redis->expire($privateKey, $ttl);
        }
    }

    /**
     * Tries to retrieve a private value by key from the cache for the given client.
     * @param string $itemKey
     * @param string $clientID
     * @return CacheResult
     */
    public function getPrivate(string $itemKey, string $clientID): CacheResult{
        $privateKey = Utility::privateKey($itemKey, $clientID);
        $value = $this->redis->get($privateKey);
        if ($value === null) {
            return new CacheResult(null, false);
        }
        return new CacheResult(unserialize($value), true);
    }

    /**
     * Gets an unprotected value by key.
     * @param string $key
     * @return CacheResult
     */
    public function getUnprotected(string $key) : CacheResult{
        $permKey = Utility::permsKey($key);
        if ($this->redis->exists($permKey)) {
            return new CacheResult(null, false);
        }
        $value = $this->redis->get(Utility::valueKey($key));

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
        $valueKey = Utility::valueKey($key);
        $permsKey = Utility::permsKey($key);
        if ($this->redis->exists($permsKey)) {
            //cant overwrite protected item as unprotected
            return;
        }

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
        $variantID = Utility::generateVariantID();
        $itemsKey = Utility::collectionItemsKeyForVariant($collectionKey, $variantID);
        $permsKey = Utility::collectionPermsKeyForVariant($collectionKey, $variantID);
        $countKey = Utility::collectionVariantCountKey($collectionKey, $variantID);

        $filterKey = Utility::collectionFilterKey($collectionKey);
        //Set of all variants keys
        $variantsSetKey = Utility::collectionVariantsSetKey($collectionKey);
        if (!empty($itemKeys)) {
            $this->redis->sadd($itemsKey, $itemKeys);
        }

        $this->redis->set($filterKey, $itemPermissionContextFilter);
        $this->redis->sadd($variantsSetKey, [$variantID]);

        $this->saveFilteredKnownClientPermissionsScript->run($permsKey, $clientID, $itemPermissionContextFilter);
        
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
        $redisCollectionKey = Utility::collectionKey($collectionKey);
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
                $this->addBackpropTarget($source, PermissionHandler::everyPermissionTypePattern(), $target);
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
        $clientPermsKey = Utility::clientPermsKey($clientID);
        
        $result = $this->getCollectionVariantsScript->run($collectionKey, $clientPermsKey);
        
        if ($result[0] === 0) {
            return new CacheResult(null, false);
        }

        $items = array_map('unserialize', $result[1]);
        return new CacheResult($items, true);
    }

    private function addBackpropTarget(string $itemKey, string $type, string $target): void{
        $targetCollectionKey = Utility::backpropTargetCollectionKey($itemKey, $type);
        $this->redis->sadd($targetCollectionKey, [$target]);
    }
}