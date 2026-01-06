<?php

namespace CanvasApiLibrary\RedisCacheProvider\Lua;

use CanvasApiLibrary\Caching\AccessAware\Interfaces\PermissionsHandlerInterface;
use CanvasApiLibrary\RedisCacheProvider\Utility;
use Predis\Client;

class AddPermissionThenBackpropagation extends AbstractScript{

    public function __construct(Client $redis, readonly PermissionsHandlerInterface $permissionHandler) {
        parent::__construct($redis);
    }

    /**
     * Runs the Lua script that adds permissions to an item and propagates them along typed backprop targets.
     * @param string[] $permissions
     * @param string[] $permissionTypes
     */
    public function run(string $itemKey, array $permissions, string $clientID): void{
        $clientPermsKey = Utility::clientPermsKey($clientID);
        if (count($permissions) === 0) {
                return;
        }

        $args = [];
        foreach ($permissions as $perm) {
                $args[] = (string)$perm;
        }

        $this->redis->evalsha(
                $this->scriptSha,
                3,
                Utility::ITEM_PREFIX,
                $itemKey,
                $clientPermsKey,
                ...$args
        );
    }


    public static function script(): string{
                return <<<LUA
local itemPrefix = KEYS[1]
local rootItemKey = KEYS[2]
local clientPermsKey = KEYS[3]
local perms = ARGV

--[[
Args: string permissions[1...n]
]]--

local function permsKey(itemKey)
    return itemPrefix .. itemKey .. ':perms'
end

--start by adding permissions to client perms
redis.call('SADD', clientPermsKey, unpack(perms))

local queue = {rootItemKey}
local visited = {}
visited[rootItemKey] = true

while #queue > 0 do
    local itemKey = table.remove(queue)

    local pk = permsKey(itemKey)
    redis.call('SADD', pk, unpack(perms))

    -- Find all backprop target keys using pattern matching
    local pattern = itemPrefix .. itemKey .. ':backprop:*'
    local backpropKeys = redis.call('KEYS', pattern)

    for _, targetsKey in ipairs(backpropKeys) do
        -- Extract type from key (format: itemPrefix..itemKey..':backprop:'..type)
        local backpropagationType = string.match(targetsKey, ':backprop:(.+)$')
        if not backpropagationType then
            --Invalid key format, crash
            return redis.error_reply("Invalid backprop key format: " .. targetsKey)
        end
        
        local targets = redis.call('SMEMBERS', targetsKey)

        local permsToPropagate = {}

        for i = 1, #perms do
            if string.match(perms[i], backpropagationType) then
                table.insert(permsToPropagate, perms[i])
            end
        end

        for _, target in ipairs(targets) do
            if not visited[target] then
                visited[target] = true
                table.insert(queue, target)
            end

            local targetPermsKey = permsKey(target)
            redis.call('SADD', targetPermsKey, unpack(permsToPropagate))
        end
        
        ::continue_backprop::
    end
end

return 1
LUA;
        }
}