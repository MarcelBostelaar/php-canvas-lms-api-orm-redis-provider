<?php

namespace CanvasApiLibrary\RedisCacheProvider\Lua;
use CanvasApiLibrary\RedisCacheProvider\Utility;

class GetIfPermitted extends AbstractScript{

    public function run(string $clientID, string $itemKey): mixed{
        $clientPermsKey = Utility::clientPermsKey($clientID);
        $itemPermsKey = Utility::permsKey($itemKey);
        $itemValueKey = Utility::valueKey($itemKey);

        $result = $this->redis->evalsha(
                $this->scriptSha,
                3,
                $clientPermsKey,
                $itemPermsKey,
                $itemValueKey
        );

        if (!is_array($result) || count($result) !== 2) {
                return null;
        }

        $authorized = (bool)$result[0];
        $value = $result[1];

        return $authorized ? $value : null;
    }
    
    protected static function script(): string{
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