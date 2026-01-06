<?php

namespace CanvasApiLibrary\RedisCacheProvider\Lua;

use CanvasApiLibrary\Caching\AccessAware\Interfaces\PermissionsHandlerInterface;
use CanvasApiLibrary\RedisCacheProvider\Utility;
use Predis\Client;

class SaveFilteredKnownClientPermissionsToKey extends AbstractScript{

    public function __construct(Client $redis, readonly PermissionsHandlerInterface $permissionHandler) {
        parent::__construct($redis);
    }

    /**
     * Runs the Lua script that filters client permissions by context pattern and saves them to a key.
     * @param string $permissionsKey The key where filtered permissions will be saved
     * @param string $clientID The client identifier
     * @param string $contextFilter Lua pattern to filter permissions
     */
    public function run(string $permissionsKey, string $clientID, string $contextFilter): void{
        $clientPermsKey = Utility::clientPermsKey($clientID);

        $this->redis->evalsha(
            $this->scriptSha,
            2,
            $clientPermsKey,
            $permissionsKey,
            $contextFilter
        );
    }


    protected static function script(): string{
        return <<<LUA
local clientPermsKey = KEYS[1]
local permsKey = KEYS[2]
local contextFilter = ARGV[1]

-- Get all client permissions
local clientPerms = redis.call('SMEMBERS', clientPermsKey)

-- Filter permissions using Lua pattern matching
local filteredPerms = {}
for _, perm in ipairs(clientPerms) do
    if string.match(perm, contextFilter) then
        table.insert(filteredPerms, perm)
    end
end

-- Add filtered permissions to the target set
if #filteredPerms > 0 then
    redis.call('SADD', permsKey, unpack(filteredPerms))
end

return #filteredPerms
LUA;
    }
}