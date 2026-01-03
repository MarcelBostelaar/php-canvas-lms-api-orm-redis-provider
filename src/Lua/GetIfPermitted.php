<?php

namespace CanvasApiLibrary\RedisCacheProvider\Lua;

class GetIfPermitted{
    public static function script(): string{
                return <<<LUA
local clientPermsKey = KEYS[1]
local itemPermsKey = KEYS[2]
local valueKey = KEYS[3]

--Intersect the known client permissions with the item permissions, if at least one matches, access is allowed
local inter = redis.call('SINTER', clientPermsKey, itemPermsKey)

if #inter > 0 then
    local value = redis.call('GET', valueKey)
    return {1, value}
end

return {0, nil}
LUA;
        }
}