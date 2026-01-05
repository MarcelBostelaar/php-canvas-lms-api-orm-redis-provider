<?php

namespace CanvasApiLibrary\RedisCacheProvider\Lua;
use CanvasApiLibrary\RedisCacheProvider\Utility;

class GetCollectionVariants extends AbstractScript{
    /**
     * Evaluates all collection variants for dominance matching.
     * Finds the best matching variant where client's filtered perms are a subset.
     * @return array{0, null}|array{1, array<string>} 0 and null if no matching variant found, 1 and array of item values if found.
     */
    public function run(string $collectionKey, string $clientPermsKey): array{
        $result = $this->redis->evalsha(
            $this->scriptSha,
            2,
            $clientPermsKey,
            $collectionKey,
            Utility::ITEM_PREFIX,
            Utility::COLLECTION_PREFIX
        );

        if ($result[0] === 0) {
            return [0, null];
        }

        return [1, array_values($result)];
    }
    public static function script(): string{
        //TODO is missing filtering of client permissions based on the collection filter.
                return <<<LUA
local clientPermsKey = KEYS[1]
local collectionKey = KEYS[2]
local itemPrefix = ARGV[1]
local collPrefix = ARGV[2]
local collectionFilterKey = collPrefix .. collectionKey .. ':filter'
local collectionFilter = redis.call('SMEMBERS', collectionFilterKey)

function isSubset(subset, supersetKey)
    local areMembers = redis.call('SMEMBERS', supersetKey, subset)
    --check if all are integer 1
    for i in areMembers do
        if areMembers[i] == 0 then
            return false
        end
    end
    return true
end

local clientPerms = redis.call('SMEMBERS', clientPermsKey)
--Do not catch client without permissions, there may be public items.

local variantsSetKey = collPrefix .. collectionKey .. ':variants'
local variants = redis.call('SMEMBERS', variantsSetKey)

if #variants == 0 then -- No variants exist, return 0 as result indicator
    return 0, {}
end

-- Sort variants by permission count (largest first)
local variantData = {}
for _, variantID in ipairs(variants) do
    local countKey = collPrefix .. collectionKey .. ':' .. variantID .. ':count'
    local count = tonumber(redis.call('GET', countKey) or '-1')
    if count >= 0 then --Expired variants will be skipped
        table.insert(variantData, {id = variantID, count = count})
    end
end

table.sort(variantData, function(a, b) return a.count > b.count end)

-- Check each variant in order (largest first)
for _, variant in ipairs(variantData) do
    local variantID = variant.id
    local variantPermsKey = collPrefix .. collectionKey .. ':' .. variantID .. ':perms'
    local variantItemsKey = collPrefix .. collectionKey .. ':' .. variantID .. ':items'
    
    local variantPerms = redis.call('SMEMBERS', variantPermsKey)
    
    -- Check if clientPerms is subset of variantPerms
    local isSubset = isSubset(clientPerms, variantPermsKey)
    if(isSubset) then
        goto continue
    end
    
    -- clientPerms is subset of variantPerms, return items filtered to client perms
    local variantItems = redis.call('SMEMBERS', variantItemsKey)
    local results = {}
    
    for _, itemKey in ipairs(variantItems) do
        local itemPermsKey = itemPrefix .. itemKey .. ':perms'
        local itemValueKey = itemPrefix .. itemKey .. ':value'
        
        local inter = redis.call('SINTER', itemPermsKey, clientPermsKey)
        if #inter > 0 then
            local v = redis.call('GET', itemValueKey)
            if v then
                table.insert(results, v)
            else
                -- Item has no value, collection is stale, go to next variant
                goto continue
            end
        end
    end
    
    return 1, results
    
    ::continue::
end

return 0, {}
LUA;
        }
}