<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Media;
use App\Models\Product;
use App\Services\CacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class ProductSyncController extends Controller
{
    public function __construct(protected CacheService $cache) {}

    public function sync(Request $request): JsonResponse
    {
        $request->validate([
            'products'                    => ['required', 'array'],
            'products.*.erp_product_id'      => ['required', 'string'],
            'products.*.parent_erp_id'       => ['sometimes', 'nullable', 'string'],
            'products.*.type'                => ['sometimes', 'string'],
            'products.*.name'                => ['required', 'string'],
            'products.*.regular_price'       => ['required', 'numeric'],
            'products.*.is_active'           => ['sometimes', 'boolean'],
            'products.*.website_url'         => ['sometimes', 'nullable', 'string', 'max:500'],
            'products.*.images'              => ['sometimes', 'array'],
            'products.*.images.*.url'        => ['required_with:products.*.images', 'string'],
            'products.*.images.*.is_primary' => ['sometimes', 'boolean'],
        ]);

        $synced = 0;
        $errors = [];

        foreach ($request->products as $data) {
            try {
                // Resolve category by ERP ID
                $category = !empty($data['erp_category_id'])
                    ? Category::where('erp_category_id', $data['erp_category_id'])->first()
                    : null;

                // Resolve sub category by ERP ID
                $subCategory = !empty($data['erp_sub_category_id'])
                    ? Category::where('erp_category_id', $data['erp_sub_category_id'])->first()
                    : null;

                // Resolve brand by ERP ID
                $brand = !empty($data['erp_brand_id'])
                    ? Brand::where('erp_brand_id', $data['erp_brand_id'])->first()
                    : null;

                // Resolve parent by ERP ID
                $parentId = null;
                if (!empty($data['parent_erp_id'])) {
                    $parentId = Product::where('erp_product_id', $data['parent_erp_id'])->value('id');
                }

                $product = Product::updateOrCreate(
                    ['erp_product_id' => $data['erp_product_id']],
                    [
                        'parent_id'        => $parentId,
                        'category_id'      => $category?->id,
                        'sub_category_id'  => $subCategory?->id,
                        'brand_id'         => $brand?->id,
                        'name'             => $data['name'],
                        'type'             => $data['type']              ?? 'simple',
                        'description'      => $data['description']       ?? null,
                        'short_description'=> $data['short_description'] ?? null,
                        'sku'              => $data['sku']               ?? null,
                        'regular_price'    => $data['regular_price'],
                        'sale_price'       => $data['sale_price']        ?? null,
                        'manage_stock'     => $data['manage_stock']      ?? true,
                        'stock_quantity'   => $data['stock_quantity']    ?? 0,
                        'in_stock'         => $data['in_stock']          ?? true,
                        'is_active'        => $data['is_active']         ?? true,
                        'attributes'       => $data['attributes']        ?? null,
                        'website_url'      => $data['website_url']       ?? null,
                        'synced_at'        => now(),
                    ]
                );

                // Sync images if provided
                if (!empty($data['images'])) {
                    $this->syncImages($product, $data['images']);
                }

                $this->cache->invalidateProduct($product->id);
                $synced++;

            } catch (\Throwable $e) {
                $errors[] = [
                    'erp_product_id' => $data['erp_product_id'],
                    'error'          => $e->getMessage(),
                ];
            }
        }

        $this->cache->invalidateProductLists();

        return response()->json([
            'message' => "Synced {$synced} products",
            'errors'  => $errors,
        ]);
    }

    private function syncImages(Product $product, array $images): void
    {
        // Remove old images and replace with fresh set from ERP
        $product->media()->delete();

        foreach ($images as $index => $image) {
            Media::create([
                'mediable_type' => Product::class,
                'mediable_id'   => $product->id,
                'url'           => $image['url'],
                'disk'          => 'external',
                'path'          => $image['url'],
                'alt'           => $image['alt']        ?? $product->name,
                'is_primary'    => $image['is_primary'] ?? ($index === 0),
                'sort_order'    => $index,
            ]);
        }
    }
}