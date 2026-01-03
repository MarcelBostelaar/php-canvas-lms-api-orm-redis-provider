<?php

namespace CanvasApiLibrary\RedisCacheProvider\Lua;
use CanvasApiLibrary\RedisCacheProvider\Utility;

class GetCollectionVariants extends AbstractScript{
    /**
     * Evaluates all collection variants for dominance matching.
     * Finds the best matching variant where client's filtered perms are a subset.
     * @return string[]|null
     */
    public function run(string $collectionKey, string $clientPermsKey): ?array{
        $result = $this->redis->evalsha(
            $this->scriptSha,
            2,
            $clientPermsKey,
            $collectionKey,
            Utility::ITEM_PREFIX,
            Utility::COLLECTION_PREFIX
        );

        if ($result === null || !is_array($result)) {
            return null;
        }

        return array_values($result);
    }
    public static function script(): string{
                return <<<LUA
local clientPermsKey = KEYS[1]
local collectionKey = KEYS[2]
local itemPrefix = ARGV[1]
local collPrefix = ARGV[2]

local clientPerms = redis.call('SMEMBERS', clientPermsKey)
if #clientPerms == 0 then
    return {}
end

local variantsSetKey = collPrefix .. collectionKey .. ':variants'
local variants = redis.call('SMEMBERS', variantsSetKey)

if #variants == 0 then
    return {}
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
    local isSubset = true
    for _, clientPerm in ipairs(clientPerms) do
        local found = false
        for _, variantPerm in ipairs(variantPerms) do
            if clientPerm == variantPerm then
                found = true
                break
            end
        end
        if not found then
            isSubset = false
            break
        end
    end
    
    -- If clientPerms is superset (not subset and all variant perms in client), skip
    if not isSubset then
        local isSupersetOrDisjoint = true
        for _, variantPerm in ipairs(variantPerms) do
            local found = false
            for _, clientPerm in ipairs(clientPerms) do
                if variantPerm == clientPerm then
                    found = true
                    break
                end
            end
            if not found then
                isSupersetOrDisjoint = false
                break
            end
        end
        
        if isSupersetOrDisjoint and #variantPerms > 0 then
            goto continue
        end
    end
    
    -- If we reach here and not subset, it's disjoint, try next
    if not isSubset then
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
            end
        end
    end
    
    if #results > 0 then
        return results
    end
    
    ::continue::
end

return {}
LUA;
        }
}