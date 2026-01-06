<?php

namespace CanvasApiLibrary\RedisCacheProvider\Lua;

use Predis\Client;

abstract class AbstractScript{
    protected abstract static function script(): string;
    protected string $scriptSha;

    public function __construct(protected readonly Client $redis) {
        $this->scriptSha = $this->redis->script('load', $this::script());
    }


}