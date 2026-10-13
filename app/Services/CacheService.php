<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class CacheService
{
    // TTLs in seconds
    const TTL_PRODUCTS = 3600;       // 1 hour

    const TTL_CATEGORIES = 86400;      // 24 hours

    const TTL_BRANDS = 86400;      // 24 hours

    // Cache key prefixes
    const KEY_PRODUCT = 'product:';

    const KEY_PRODUCT_LIST = 'products:list:';

    const KEY_CATEGORY = 'category:';

    const KEY_CATEGORY_LIST = 'categories:all';

    const KEY_BRAND = 'brand:';

    const KEY_BRAND_LIST = 'brands:all';

    // -------------------------------------------------------
    // Products
    // -------------------------------------------------------

    public function getProduct(string|int $key): mixed
    {
        return Cache::get(self::KEY_PRODUCT.$key);
    }

    public function putProduct(string|int $key, mixed $data): void
    {
        Cache::put(self::KEY_PRODUCT.$key, $data, self::TTL_PRODUCTS);
    }

    /**
     * Retrieve the current global product version timestamp for cache busting list fingerprints dynamically.
     */
    protected function getProductVersion(): string
    {
        return (string) Cache::rememberForever('global_product_version', fn () => self::newVersion());
    }

    public function getProductList(string $fingerprint): mixed
    {
        $versionedKey = self::KEY_PRODUCT_LIST.$this->getProductVersion().':'.$fingerprint;

        return Cache::get($versionedKey);
    }

    public function putProductList(string $fingerprint, mixed $data): void
    {
        $versionedKey = self::KEY_PRODUCT_LIST.$this->getProductVersion().':'.$fingerprint;
        Cache::put($versionedKey, $data, self::TTL_PRODUCTS);
    }

    // -------------------------------------------------------
    // Categories
    // -------------------------------------------------------

    public function getCategories(): mixed
    {
        return Cache::get(self::KEY_CATEGORY_LIST);
    }

    public function putCategories(mixed $data): void
    {
        Cache::put(self::KEY_CATEGORY_LIST, $data, self::TTL_CATEGORIES);
    }

    public function getCategory(int $id): mixed
    {
        return Cache::get(self::KEY_CATEGORY.$id);
    }

    public function putCategory(int $id, mixed $data): void
    {
        Cache::put(self::KEY_CATEGORY.$id, $data, self::TTL_CATEGORIES);
    }

    // -------------------------------------------------------
    // Brands
    // -------------------------------------------------------

    public function getBrands(): mixed
    {
        return Cache::get(self::KEY_BRAND_LIST);
    }

    public function putBrands(mixed $data): void
    {
        Cache::put(self::KEY_BRAND_LIST, $data, self::TTL_BRANDS);
    }

    public function getBrand(int $id): mixed
    {
        return Cache::get(self::KEY_BRAND.$id);
    }

    public function putBrand(int $id, mixed $data): void
    {
        Cache::put(self::KEY_BRAND.$id, $data, self::TTL_BRANDS);
    }

    // -------------------------------------------------------
    // Cache invalidation — called by sync controllers
    // when ERP pushes updated data
    // -------------------------------------------------------

    public function invalidateProduct(int $id): void
    {
        Cache::forget(self::KEY_PRODUCT.$id);
        Cache::forget(self::KEY_PRODUCT.$id.':siblings');
        // Also clear all list caches since a product changed
        $this->invalidateProductLists();
    }

    public function invalidateProductLists(): void
    {
        // Because Database/File drivers do not support wildcard deleteMatching or tags natively,
        // we step the global version key. This immediately orphans all existing product lists,
        // forcing them to be recreated. Old lists will just safely evaporate once their TTL expires.
        // A fresh random value, not time(): a list cached earlier in the same second must not survive the bump.
        Cache::forever('global_product_version', self::newVersion());
    }

    public function invalidateCategory(int $id): void
    {
        Cache::forget(self::KEY_CATEGORY.$id);
        Cache::forget(self::KEY_CATEGORY_LIST);
    }

    public function invalidateBrand(int $id): void
    {
        Cache::forget(self::KEY_BRAND.$id);
        Cache::forget(self::KEY_BRAND_LIST);
    }

    public function invalidateAll(): void
    {
        Cache::flush();
    }

    private static function newVersion(): string
    {
        return bin2hex(random_bytes(8));
    }
}
