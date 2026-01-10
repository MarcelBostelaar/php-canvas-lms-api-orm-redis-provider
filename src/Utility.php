<?php

namespace CanvasApiLibrary\RedisCacheProvider;

class Utility{
    
    public const ITEM_PREFIX = 'item:';
    public const CLIENT_PREFIX = 'client:';
    public const COLLECTION_PREFIX = 'collection:';

    public static function valueKey(string $itemKey): string{
            return self::ITEM_PREFIX . $itemKey . ':value';
    }

    public static function privateKey(string $itemKey, string $clientID): string{
            return self::ITEM_PREFIX . $itemKey . ':private:' . $clientID;
    }

    public static function permsKey(string $itemKey): string{
            return self::ITEM_PREFIX . $itemKey . ':perms';
    }

    public static function backpropTargetCollectionKey(string $itemKey, string $type): string{
            return self::ITEM_PREFIX . $itemKey . ':backprop:' . $type;
    }

    public static function clientPermsKey(string $clientID): string{
            return self::CLIENT_PREFIX . $clientID . ':perms';
    }

    public static function collectionKey(string $collectionKey): string{
        return self::COLLECTION_PREFIX . $collectionKey . ':items';
    }

    public static function collectionVariantsSetKey(string $collectionKey): string{
        return self::COLLECTION_PREFIX . $collectionKey . ':variants';
    }

    public static function collectionItemsKeyForVariant(string $collectionKey, string $variantID): string{
        return self::COLLECTION_PREFIX . $collectionKey . ':' . $variantID . ':items';
    }

    public static function collectionFilterKey(string $collectionKey): string{
        return self::COLLECTION_PREFIX . $collectionKey . ':filter';
    }

    public static function collectionPermsKeyForVariant(string $collectionKey, string $variantID): string{
        return self::COLLECTION_PREFIX . $collectionKey . ':' . $variantID . ':perms';
    }

    public static function collectionVariantCountKey(string $collectionKey, string $variantID): string{
        return self::COLLECTION_PREFIX . $collectionKey . ':' . $variantID . ':count';
    }

    public static function generateVariantID(): string{
        return \uniqid('var_', true);
    }
}