<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\HomepageConfig;
use App\Models\HomepageSectionItem;
use App\Models\Product;
use App\Support\CatalogScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class HomepageController extends Controller
{
    /**
     * Products here are formatted by ProductController, so they look exactly
     * like /api/products: prices only for approved accounts, and a
     * catalog-limited account (CatalogScope) sees only its own catalog.
     */
    public function __construct(private ProductController $products) {}

    public function show(Request $request)
    {
        $clientKey = $request->input('client');

        if (! $clientKey) {
            return response()->json(['message' => 'The client parameter is required.'], 422);
        }

        $config = HomepageConfig::where('client_key', $clientKey)
            ->where('is_active', true)
            ->first();

        if (! $config) {
            return response()->json(['message' => 'Homepage config not found.'], 404);
        }

        $sections   = $config->activeSections()->with('activeItems')->get();
        $showPrices = $this->products->canSeePrices();
        $scope      = CatalogScope::ids();

        $resolved = $sections->map(function ($section) use ($showPrices, $scope) {
            $data = $this->resolveData($section->type, $section->settings ?? [], $showPrices, $scope);

            // A catalog-limited account never sees a category outside its catalog.
            if ($scope !== null && $section->type === 'featured_category' && $data === null) {
                return null;
            }

            return [
            'id'       => $section->id,
            'type'     => $section->type,
            'title'    => $section->title,
            'sort_order' => $section->sort_order,
            'is_active' => $section->is_active,
            'settings' => (object) ($section->settings ?? []),
            'data'     => $data,
            'items'    => $section->activeItems->map(fn ($item) => [
                'id' => $item->id,
                'kind' => $item->kind,
                'title' => $item->title,
                'desktop_image_url' => $this->imageUrl($item->desktop_image_path, $item->desktop_image_external_url),
                'mobile_image_url' => $this->imageUrl($item->mobile_image_path, $item->mobile_image_external_url),
                'pdf_url' => $item->pdf_path
                    ? route('homepage.catalog.pdf', ['item' => $item->id])
                    : null,
                'alt_text' => $item->alt_text ?: '',
                'link_url' => $item->link_url,
                'sort_order' => $item->sort_order,
                'is_active' => $item->is_active,
            ])->values(),
            ];
        })->filter()->values();

        $siteSettings = $config->site_settings ?? [];

        return response()->json([
            'client'   => $config->client_key,
            'label'    => $config->label,
            'site'     => array_merge($siteSettings, [
                'logo_url' => $config->site_logo_path
                    ? url(Storage::disk('public')->url($config->site_logo_path))
                    : ($siteSettings['logo_url'] ?? null),
                'catalog_background_logo_url' => $config->catalog_background_path
                    ? url(Storage::disk('public')->url($config->catalog_background_path))
                    : ($siteSettings['catalog_background_logo_url'] ?? null),
            ]),
            'sections' => $resolved,
        ]);
    }

    public function catalogPdf(HomepageSectionItem $item)
    {
        $item->load('section.config');
        abort_unless(
            $item->kind === 'catalog'
            && $item->is_active
            && $item->section?->type === 'catalog_showcase'
            && $item->section->is_active
            && $item->section->config?->is_active
            && $item->pdf_path
            && str_starts_with($item->pdf_path, 'homepage/')
            && ! str_contains($item->pdf_path, '..')
            && Storage::disk('public')->exists($item->pdf_path),
            404,
        );

        return response()->file(Storage::disk('public')->path($item->pdf_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str($item->title ?: 'catalog')->slug().'.pdf"',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function imageUrl(?string $path, ?string $externalUrl): ?string
    {
        if ($path) {
            return url(Storage::disk('public')->url($path));
        }

        return $externalUrl;
    }

    private function resolveData(string $type, array $settings, bool $showPrices, ?array $scope): array|null
    {
        return match ($type) {
            'featured_category' => $this->resolveFeaturedCategory($settings, $showPrices, $scope),
            'brand_showcase'    => $this->resolveBrandShowcase($settings, $scope),
            'product_carousel'  => $this->resolveProductCarousel($settings, $scope),
            default             => null,
        };
    }

    private function resolveFeaturedCategory(array $settings, bool $showPrices, ?array $scope): array|null
    {
        $categoryId = $settings['category_id'] ?? null;
        if (! $categoryId || ($scope !== null && ! in_array((int) $categoryId, $scope))) {
            return null;
        }

        $category = Category::with('primaryImage')->find($categoryId);

        if (! $category) {
            return null;
        }

        $limit = (int) ($settings['product_limit'] ?? 8);
        $query = Product::where('category_id', $categoryId)
            ->where('is_active', true)
            ->where('in_stock', true)
            ->whereNull('parent_id')
            ->with(['category', 'subCategory', 'brand', 'primaryImage']);

        if ($scope !== null) {
            CatalogScope::restrictProducts($query, $scope);
        }

        return [
            'category' => [
                'id'          => $category->id,
                'name'        => $category->name,
                'slug'        => $category->slug,
                'description' => $category->description,
                'image'       => $category->primaryImage?->public_url,
            ],
            'products' => $query->limit($limit)->get()
                ->map(fn (Product $product) => $this->products->formatProduct($product, showPrices: $showPrices))
                ->values(),
        ];
    }

    private function resolveBrandShowcase(array $settings, ?array $scope): array|null
    {
        $brandIds = $settings['brand_ids'] ?? [];
        if (empty($brandIds)) {
            return null;
        }

        $query = Brand::whereIn('id', $brandIds)->where('is_active', true)->with('primaryImage');

        // A catalog-limited account only sees brands it has products of.
        if ($scope !== null) {
            $query->whereIn('id', CatalogScope::restrictProducts(Product::query()->where('is_active', true), $scope)->select('brand_id'));
        }

        $brands = $query->get()
            ->sortBy(fn ($b) => array_search($b->id, $brandIds))
            ->map(fn (Brand $brand) => [
                'id'    => $brand->id,
                'name'  => $brand->name,
                'slug'  => $brand->slug,
                'image' => $brand->primaryImage?->public_url,
            ])
            ->values();

        return ['brands' => $brands];
    }

    private function resolveProductCarousel(array $settings, ?array $scope): array
    {
        $limit = min(max((int) ($settings['limit'] ?? 14), 1), 50);
        $requestedIds = array_slice(array_values(array_unique(array_map('intval', $settings['product_ids'] ?? []))), 0, $limit);
        $existing = Product::query()
            ->whereIn('id', $requestedIds)
            ->where('is_active', true)
            ->whereNull('parent_id');

        if ($scope !== null) {
            CatalogScope::restrictProducts($existing, $scope);
        }

        $existingIds = $existing->pluck('id')->all();
        $existingLookup = array_flip($existingIds);

        return [
            'product_ids' => array_values(array_filter(
                $requestedIds,
                fn (int $id) => isset($existingLookup[$id]),
            )),
        ];
    }
}
