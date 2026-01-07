<?php

namespace CanvasApiLibrary\RedisCacheProvider\Tests;

use CanvasApiLibrary\RedisCacheProvider\CacheProvider;
use CanvasApiLibrary\RedisCacheProvider\PermissionHandler;
use CanvasApiLibrary\RedisCacheProvider\Utility;
use PHPUnit\Framework\TestCase;
use Predis\Client;


final class CacheProviderIntegrationTest extends TestCase
{
    protected CacheProvider $provider;
protected Client $redis;

    protected function setUp(): void
    {
        $this->redis = new Client([
            'host' => '127.0.0.1',
            'port' => 6379,
            'timeout' => 1.0,
            'scheme' => 'tcp'
        ]);

        $this->redis->ping();

        $this->redis->flushdb();
        $this->provider = new CacheProvider($this->redis, new PermissionHandler());
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->flushdb();
        }
    }

    public function testSetAndGetWithPermissions(): void
    {
        $this->provider->set('item-1', ['name' => 'one'], 99999, 'client-a', 'perm:read');

        $result = $this->provider->get('client-a', 'item-1');

        $this->assertTrue($result->hit);
        $this->assertSame(['name' => 'one'], $result->value);
    }

    public function testGetDeniesAccessWithoutPermissions(): void
    {
        $this->provider->set('item-2', 'secret', 99999, 'client-owner', 'perm:secret');

        $result = $this->provider->get('client-guest', 'item-2');

        $this->assertFalse($result->hit);
        $this->assertNull($result->value);
    }

    public function testGetUnprotectedBypassesPermissions(): void
    {
        $this->provider->setUnprotected('public-item', 'hello-world', 99999);

        $result = $this->provider->getUnprotected('public-item');

        $this->assertTrue($result->hit);
        $this->assertSame('hello-world', $result->value);
    }

    public function testGetUnprotectedDenied(): void
    {
        $this->provider->set('item-1', 'secret', 99999, 'client-owner', 'perm:secret');

        $result = $this->provider->getUnprotected('item-1');

        $this->assertFalse($result->hit);
        $this->assertNull($result->value);
    }

    public function testPermissionUnion(): void
    {
        $this->provider->setPermissionUnion('item-root', 'item-shadow');

        $this->provider->set('item-root', 'Root', 99999, 'client-x', 'perm:union');

        $shadowPerms = $this->redis->smembers(Utility::permsKey('item-shadow'));

        $this->assertContains('perm:union', $shadowPerms, 'Backpropagated permission was not stored on the union target.');
    }

    public function testBackpropagation(): void
    {
        $collectionKey = 'bp-collection';
        $childKey = 'bp-child';
        $parentKey = 'bp-parent';
        $permissionPattern = 'perm:type:%d+';

        // Prepare collection membership so setBackpropagation knows which items to configure.
        $this->redis->sadd(Utility::collectionKey($collectionKey), [$childKey]);

        $this->provider->setBackpropagation($collectionKey, $permissionPattern, $parentKey);

        // Add a permission that matches the backprop pattern to the child.
        $this->provider->set($childKey, 'Child payload', 99999, 'client-bp', 'perm:type:42');

        $parentPerms = $this->redis->smembers(Utility::permsKey($parentKey));

        $this->assertContains('perm:type:42', $parentPerms, 'Parent item did not receive backpropagated permission.');
    }

    public function testBackpropagationOtherContext(): void
    {
        $collectionKey = 'bp-collection';
        $childKey = 'bp-child';
        $parentKey = 'bp-parent';
        $permissionPattern = 'perm:type:%d+';

        // Prepare collection membership so setBackpropagation knows which items to configure.
        $this->redis->sadd(Utility::collectionKey($collectionKey), [$childKey]);

        $this->provider->setBackpropagation($collectionKey, $permissionPattern, $parentKey);

        // Add a permission that matches the backprop pattern to the child.
        $this->provider->set($childKey, 'Child payload', 99999, 'client-bp', 'perm:othertype:42');

        $parentPerms = $this->redis->smembers(Utility::permsKey($parentKey));

        $this->assertNotContains('perm:type:42', $parentPerms, 'Parent item did receive backpropagated permission.');
    }

    public function testCollectionRetrievalReturnsItemsWhenSubsetMatches(): void
    {
        $this->provider->set('item-a', 'A', 99999, 'client-alpha', 'perm:x:1');
        $this->provider->set('item-b', 'B', 99999, 'client-alpha', 'perm:x:2');
        $this->provider->set('item-c', 'C', 99999, 'client-alpha', 'perm:x:3');

        $this->provider->setCollection('client-alpha', 'collection-1', ['item-a', 'item-b', 'item-c'], 99999, 'perm:x:.*');

        
        $this->provider->set('unrelated', 'lorem ipsum', 99999, 'client-beta', 'perm:x:1', 'perm:x:2');

        $result = $this->provider->getCollection('client-beta', 'collection-1');

        $this->assertTrue($result->hit, 'Collection lookup should have been a cache hit for matching permissions.');

        $values = $result->value;
        sort($values);

        $this->assertSame(['A', 'B'], $values, 'Collection items returned do not match expected subset.');
    }

    public function testCollectionMissWhenClientHasExtraFilteredPermissions(): void
    {
        $collectionKey = 'collection-subset-miss';

        // Owner stores items and variants with read permissions 1 and 2.
        $this->provider->set('miss-a', 'A', 99999, 'client-owner', 'perm:read:1');
        $this->provider->set('miss-b', 'B', 99999, 'client-owner', 'perm:read:2');
        $this->provider->setCollection('client-owner', $collectionKey, ['miss-a', 'miss-b'], 99999, 'perm:read:%d+');

        // Client has a non-subset of the stored permissions (1 and 3), so should miss.
        $this->provider->set('miss-extra', 'X', 99999, 'client-seeker', 'perm:read:1', 'perm:read:3');

        $result = $this->provider->getCollection('client-seeker', $collectionKey);

        $this->assertFalse($result->hit, 'Collection lookup should have been a cache miss due to extra permissions.');
        $this->assertNull($result->value, 'Collection value should be null on cache miss.');
    }

    public function testCollectionHitWhenClientFilteredPermissionsMatchExactly(): void
    {
        $collectionKey = 'collection-subset-hit';

        $this->provider->set('hit-a', 'A', 99999, 'client-owner2', 'perm:view:1');
        $this->provider->set('hit-b', 'B', 99999, 'client-owner2', 'perm:view:2');
        $this->provider->setCollection('client-owner2', $collectionKey, ['hit-a', 'hit-b'], 99999, 'perm:view:%d+');

        // Client has the exact same filtered permission set.
        $this->provider->set('hit-extra', 'ignored', 99999, 'client-match', 'perm:view:1', 'perm:view:2');

        $result = $this->provider->getCollection('client-match', $collectionKey);

        $this->assertTrue($result->hit);
        $values = $result->value;
        sort($values);

        $this->assertSame(['A', 'B'], $values, 'Collection items returned do not match expected subset on exact permission match.');
    }
}
