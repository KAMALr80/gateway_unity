<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Product;
use App\Services\CacheService;
use App\Support\CatalogScope;
use Illuminate\Http\JsonResponse;

class BrandController extends Controller
{
    public function __construct(protected CacheService $cache) {}

    public function index(): JsonResponse
    {
        // A scoped user sees only brands they have products of; worked out
        // live, outside the shared cache.
        if (($scope = CatalogScope::ids()) !== null) {
            $brandIds = CatalogScope::restrictProducts(Product::query()->where('is_active', true), $scope)
                ->whereNotNull('brand_id')
                ->distinct()
                ->pluck('brand_id');

            return response()->json(['data' => $this->formatBrands(
                Brand::with('primaryImage')->where('is_active', true)->whereIn('id', $brandIds)->orderBy('name')->get()
            )]);
        }

        $cached = $this->cache->getBrands();

        if ($cached) {
            return response()->json($cached);
        }

        $brands = $this->formatBrands(Brand::with('primaryImage')
            ->where('is_active', true)
            ->orderBy('name')
            ->get());

        $data = ['data' => $brands];

        $this->cache->putBrands($data);

        return response()->json($data);
    }

    private function formatBrands($brands): array
    {
        return $brands->map(fn($b) => [
            'id'    => $b->id,
            'name'  => $b->name,
            'slug'  => $b->slug,
            'image' => $b->primaryImage?->public_url,
        ])->values()->all();
    }

    public function show(int $id): JsonResponse
    {
        $cached = $this->cache->getBrand($id);

        if ($cached) {
            return response()->json($cached);
        }

        $brand = Brand::with(['primaryImage', 'media'])
            ->where('is_active', true)
            ->findOrFail($id);

        $data = [
            'id'          => $brand->id,
            'name'        => $brand->name,
            'slug'        => $brand->slug,
            'description' => $brand->description,
            'image'       => $brand->primaryImage?->public_url,
        ];

        $this->cache->putBrand($id, $data);

        return response()->json($data);
    }
}
