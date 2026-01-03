<?php

namespace CanvasApiLibrary\RedisCacheProvider\Lua;

class GetCollectionDominance{
    public static function script(): string{
                return <<<LUA
local clientPermsKey = KEYS[1]
local itemsKey = KEYS[2]
local collectionPermsKey = KEYS[3]
local itemPrefix = ARGV[1]

local clientPerms = redis.call('SMEMBERS', clientPermsKey)
if #clientPerms == 0 then
    return {}
end

local knownPerms = redis.call('SMEMBERS', collectionPermsKey)

local function isSubset(clientPerms, knownPerms)
    for _, clientPerm in ipairs(clientPerms) do
        local found = false
        for _, knownPerm in ipairs(knownPerms) do
            if clientPerm == knownPerm then
                found = true
                break
            end
        end
        if not found then
            return false
        end
    end
    return true
end

if not isSubset(clientPerms, knownPerms) then
    return {}
end

local items = redis.call('SMEMBERS', itemsKey)
local results = {}

for _, itemKey in ipairs(items) do
    local valueKey = itemPrefix .. itemKey .. ':value'
    local v = redis.call('GET', valueKey)
    if v then
        table.insert(results, v)
    end
end

return results
LUA;
        }
}