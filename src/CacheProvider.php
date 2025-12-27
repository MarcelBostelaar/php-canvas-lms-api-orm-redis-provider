<?php

namespace CanvasApiLibrary\RedisCacheProvider;

use CanvasApiLibrary\Caching\AccessAware\Interfaces\CacheProviderInterface;
use CanvasApiLibrary\Caching\AccessAware\Interfaces\CacheResult;

/**
 * @phpstan-type Permission string
 * @phpstan-type ContextFilter string
 * @phpstan-type PermissionType string
 * @implements CacheProviderInterface<Permission, ContextFilter, PermissionType>
 */
class CacheProvider implements CacheProviderInterface{
    public function __construct(private readonly PermissionHandler $permissionHandler){
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
        //TODO
    }

    /**
     * Tries to retrieve a value by key from the cache. Will do so if the client has any matching permission for any of the permissions of this item.
     * Permission bound cache operation.
     * @param string $clientID Id by which to identify this client.
     * @param string $key Key in the cache
     * @return CacheResult
     */
    public function get(string $clientID, string $key) : CacheResult{
        //TODO
    }

    /**
     * Gets an unprotected value by key.
     * @param string $key
     * @return CacheResult
     */
    public function getUnprotected(string $key) : CacheResult{
        //TODO
    }

    /**
     * Sets a value in the cache without permissions.
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     * @return void
     */
    public function setUnprotected(string $key, mixed $value, int $ttl) : void{
        //TODO
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
        //TODO
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
        //TODO. Do not implement cirtularity protection, leave that to the user of this library.
    }

    /**
     * Configures all the items with the given keys to share permissions. Used for synching permissions of different model form of the same instance.
     * @param string[] $keys
     * @return void
     */
    public function setPermissionUnion(string ...$keys){
        //TODO
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
        //TODO
    }
}