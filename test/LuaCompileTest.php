<?php

namespace CanvasApiLibrary\RedisCacheProvider\Tests;

use CanvasApiLibrary\RedisCacheProvider\Lua\AddPermissionThenBackpropagation;
use CanvasApiLibrary\RedisCacheProvider\Lua\GetCollectionVariants;
use CanvasApiLibrary\RedisCacheProvider\Lua\GetIfPermitted;
use CanvasApiLibrary\RedisCacheProvider\Lua\SaveFilteredKnownClientPermissionsToKey;
use CanvasApiLibrary\RedisCacheProvider\PermissionHandler;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use function PHPUnit\Framework\assertTrue;

final class LuaCompileTest extends TestCase
{
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
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->flushdb();
        }
    }

    public function testAddPermissionThenBackpropagation():void{
        new AddPermissionThenBackpropagation($this->redis, new PermissionHandler());
        assertTrue(true);
    }

    public function testGetCollectionVariants():void{
        new GetCollectionVariants($this->redis);
        assertTrue(true);
    }

    public function testGetIfPermitted():void{
        new GetIfPermitted($this->redis);
        assertTrue(true);
    }

    public function testSaveFilteredKnownClientPermissionsToKey():void{
        new SaveFilteredKnownClientPermissionsToKey($this->redis, new PermissionHandler());
        assertTrue(true);
    }
}