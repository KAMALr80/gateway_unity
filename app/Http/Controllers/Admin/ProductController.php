<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Media;
use App\Models\Product;
use App\Models\WishlistItem;
use App\Services\CacheService;
use App\Services\MediaThumbnails;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->input('per_page', 15), 100);

        $query = Product::with(['category', 'brand', 'primaryImage']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere(Product::codeMatcher($search));
            });
        }

        if ($categoryId = $request->input('category_id')) {
            $query->where(function ($q) use ($categoryId) {
                $q->where('category_id', $categoryId)
                    ->orWhere('sub_category_id', $categoryId);
            });
        }

        if ($brandId = $request->input('brand_id')) {
            $query->where('brand_id', $brandId);
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        if ($request->input('in_stock') !== null && $request->input('in_stock') !== '') {
            $query->where('in_stock', filter_var($request->input('in_stock'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->input('is_featured') !== null && $request->input('is_featured') !== '') {
            $query->where('is_featured', filter_var($request->input('is_featured'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->input('best_seller') !== null && $request->input('best_seller') !== '') {
            filter_var($request->input('best_seller'), FILTER_VALIDATE_BOOLEAN)
                ? $query->whereNotNull('best_seller_rank')->reorder('best_seller_rank')
                : $query->whereNull('best_seller_rank');
        }

        if ($request->input('is_active') !== null && $request->input('is_active') !== '') {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $products = $query->latest()->paginate($perPage)->withQueryString();

        $categories = Category::orderBy('name')->get(['id', 'name']);
        $brands = Brand::orderBy('name')->get(['id', 'name']);

        return Inertia::render('admin/products/index', [
            'products' => $this->paginatedData($products),
            'categories' => $categories,
            'brands' => $brands,
            'filters' => $request->only(['search', 'category_id', 'brand_id', 'type', 'in_stock', 'is_active', 'is_featured', 'best_seller', 'per_page']),
        ]);
    }

    /**
     * Adds the product to, or removes it from, the storefront's featured products. Only this flag changes;
     * everything else about a product comes from the ERP.
     */
    public function updateFeatured(Request $request, Product $product, CacheService $cache)
    {
        $validated = $request->validate(['is_featured' => ['required', 'boolean']]);

        $product->forceFill(['is_featured' => $validated['is_featured']])->save();
        if ($product->is_featured) {
            $this->warmImagesAfterResponse($product);
        }
        $cache->invalidateProduct($product->id); // also drops every cached product list

        return back()->with('success', $product->is_featured ? 'Added to featured products.' : 'Removed from featured products.');
    }

    /**
     * Adds the product to the Best Sellers page (at the given rank, or after the last one), changes its rank, or
     * removes it. Only this rank changes; everything else about a product comes from the ERP.
     */
    public function updateBestSeller(Request $request, Product $product, CacheService $cache)
    {
        $validated = $request->validate([
            'best_seller' => ['required', 'boolean'],
            'rank' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ]);

        $rank = null;
        if ($validated['best_seller']) {
            $rank = $validated['rank'] ?? $product->best_seller_rank ?? ((int) Product::max('best_seller_rank') + 1);
        }

        $product->forceFill(['best_seller_rank' => $rank])->save();
        if ($rank) {
            $this->warmImagesAfterResponse($product);
        }
        $cache->invalidateProduct($product->id); // also drops every cached product list

        return back()->with('success', $rank ? "Best seller rank #{$rank}." : 'Removed from best sellers.');
    }

    public function show(Product $product)
    {
        $product->load([
            'category',
            'subCategory',
            'brand',
            'parent',
            'children' => fn ($q) => $q->with('primaryImage'),
            'media',
            'barcodes',
        ]);

        $wishlistCount = WishlistItem::where('product_id', $product->id)->count();

        return Inertia::render('admin/products/show', [
            'product' => $product,
            'wishlistCount' => $wishlistCount,
        ]);
    }

    /**
     * A product just put on the Featured or Best Sellers page gets its image sizes made right after the response,
     * so shoppers do not wait for the first, slow download from the ERP host. The listing shows a variant's parent.
     */
    private function warmImagesAfterResponse(Product $product): void
    {
        $ids = array_values(array_filter([$product->id, $product->parent_id]));

        dispatch(function () use ($ids) {
            $thumbnails = app(MediaThumbnails::class);
            if (! $thumbnails->canEncode()) {
                return;
            }
            $largest = max($thumbnails->sizes());
            Media::query()->where('mediable_type', Product::class)->whereIn('mediable_id', $ids)->where('is_primary', true)->get()
                ->filter(fn (Media $media) => $thumbnails->eligible($media->public_url))
                ->each(fn (Media $media) => $thumbnails->resolve($media, $largest));
        })->afterResponse();
    }
}
