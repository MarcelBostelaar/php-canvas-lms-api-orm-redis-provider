<?php

namespace CanvasApiLibrary\RedisCacheProvider\Lua;

class GetCollection{
    public static function script(): string{
                return <<<LUA
local clientPermsKey = KEYS[1]
local itemsKey = KEYS[2]
local filtersKey = KEYS[3]
local itemPrefix = ARGV[1]

--If no permissions for client, return empty array
local clientPerms = redis.call('SMEMBERS', clientPermsKey)
if #clientPerms == 0 then
    return {}
end

local items = redis.call('SMEMBERS', itemsKey)
local results = {}

for _, itemKey in ipairs(items) do
    local permsKey = itemPrefix .. itemKey .. ':perms'
    local valueKey = itemPrefix .. itemKey .. ':value'

    local inter = redis.call('SINTER', permsKey, clientPermsKey)
    if #inter > 0 then
        local v = redis.call('GET', valueKey)
        if v then
            table.insert(results, v)
        end
    end
end

return results
LUA;
        }
}