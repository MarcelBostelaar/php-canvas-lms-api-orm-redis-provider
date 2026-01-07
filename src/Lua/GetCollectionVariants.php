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
        return $this->redis->evalsha(
            $this->scriptSha,
            2,
            $clientPermsKey,
            $collectionKey,
            Utility::ITEM_PREFIX,
            Utility::COLLECTION_PREFIX
        );
    }
    protected static function script(): string{
                return <<<LUA
local clientPermsKey = KEYS[1]
local collectionKey = KEYS[2]
local itemPrefix = ARGV[1]
local collPrefix = ARGV[2]
local collectionFilterKey = collPrefix .. collectionKey .. ':filter'
local collectionFilter = redis.call('GET', collectionFilterKey)

local function isSubset(subset, supersetKey)
    local areMembers = redis.call('SMISMEMBER', supersetKey, unpack(subset))
    --check if all are integer 1
    for _, i in ipairs(areMembers) do
        if i == 0 then
            return false
        end
    end
    return true
end

local clientPerms = redis.call('SMEMBERS', clientPermsKey)
--Do not catch client without permissions, there may be public items.

--Filter clientPerms based on collection filter
local filteredClientPerms = {}
for _, perm in ipairs(clientPerms) do
    if string.match(perm, collectionFilter) then
        table.insert(filteredClientPerms, perm)
    end
end

local variantsSetKey = collPrefix .. collectionKey .. ':variants'
local variants = redis.call('SMEMBERS', variantsSetKey)

if #variants == 0 then -- No variants exist, return 0 as result indicator
    return {0, {}}
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

local results = {}
-- Check each variant in order (largest first)
for _, variant in ipairs(variantData) do
    local skipThisVariant = false
    local variantID = variant.id
    local variantPermsKey = collPrefix .. collectionKey .. ':' .. variantID .. ':perms'
    local variantItemsKey = collPrefix .. collectionKey .. ':' .. variantID .. ':items'
    
    local variantPerms = redis.call('SMEMBERS', variantPermsKey)
    
    -- Check if clientPerms is subset of variantPerms
    local isSubset = isSubset(filteredClientPerms, variantPermsKey)
    if not isSubset then
        skipThisVariant = true
    else
        -- clientPerms is subset of variantPerms, return items filtered to client perms
        local variantItems = redis.call('SMEMBERS', variantItemsKey)
        --Empty results for this variant
        results = {}
        
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
                    skipThisVariant = true
                end
            end
        end
    end

    if not skipThisVariant then
        return {1, results}
    end
end

return {0, {}}
LUA;
        }
}