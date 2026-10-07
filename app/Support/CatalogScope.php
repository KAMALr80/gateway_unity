<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * What part of the catalog the signed-in user may see.
 *
 * Most users see everything (ids() is null). A user with catalog_category_id
 * set (e.g. an App Store review account) sees only that category and its
 * sub-categories: in product lists and search, product detail, categories,
 * brands, wishlist and checkout. Variations carry their group's category, so
 * a group and its variations are in or out together.
 *
 * Catalog responses are cached for everyone, so a scoped request must never
 * read or fill the shared cache entries it narrows.
 */
class CatalogScope
{
    /** @var array<string, array<int>> per user and category, for one request */
    private static array $resolved = [];

    /** Category ids the user may see, or null for the whole catalog. */
    public static function ids(?User $user = null): ?array
    {
        $user ??= auth('api')->user();

        if (! $user || ! $user->catalog_category_id) {
            return null;
        }

        return self::$resolved[$user->id . ':' . $user->catalog_category_id] ??= Category::query()
            ->where('id', $user->catalog_category_id)
            ->orWhere('parent_id', $user->catalog_category_id)
            ->pluck('id')
            ->all();
    }

    public static function restrictProducts(Builder $query, array $ids): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereIn('category_id', $ids)->orWhereIn('sub_category_id', $ids));
    }

    public static function allowsProduct(Product $product, array $ids): bool
    {
        return in_array($product->category_id, $ids) || in_array($product->sub_category_id, $ids);
    }

    /** For tests: forget what was resolved in this process. */
    public static function flush(): void
    {
        self::$resolved = [];
    }
}
