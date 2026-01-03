<?php

namespace CanvasApiLibrary\RedisCacheProvider\Lua;

use Predis\Client;

abstract class AbstractScript{
    public abstract static function script(): string;
    protected static string $scriptSha;

    public function __construct(protected readonly Client $redis) {
        self::$scriptSha = $this->redis->script('load', $this::script());
    }


}