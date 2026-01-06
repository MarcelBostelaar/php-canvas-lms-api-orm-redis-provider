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
        //TODO modify script to add given permissions to client as well.
        //TODO rewrite to use type as pattern to match the key
        $permissionTypes = array_map([$this->permissionHandler, 'typeFromPermission'], $permissions);
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
        $args[] = Utility::ITEM_PREFIX;

        $this->redis->evalsha(
                $this->scriptSha,
                0,
                ...$args
        );
    }


    public static function script(): string{
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
    types[i] = ARGV[2 + permCount + i]
end

local itemPrefix = ARGV[2 + (permCount * 2) + 1]

local function permsKey(itemKey)
    return itemPrefix .. itemKey .. ':perms'
end

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

        for i = 1, permCount do
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