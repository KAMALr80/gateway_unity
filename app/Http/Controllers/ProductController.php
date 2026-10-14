<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\CacheService;
use App\Services\MediaThumbnails;
use App\Support\CatalogScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(protected CacheService $cache, protected MediaThumbnails $thumbnails) {}

    public function index(Request $request): JsonResponse
    {
        // Bad paging/search input is a 422; it used to reach the query as LIMIT -5 or an array (HTTP 500).
        // An oversized per_page is still capped below, as documented.
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string'],
            'featured' => ['nullable', 'boolean'],
            'best_seller' => ['nullable', 'boolean'],
        ]);

        $showPrices = $this->canSeePrices();
        $scope = CatalogScope::ids();

        // Build a fingerprint from query params so each
        // unique filter combination has its own cache entry.
        // Price visibility is included so guests get their own cached variant.
        $fingerprint = md5(json_encode(array_merge(
            $request->only([
                'category_id',
                'sub_category_id',
                'brand_id',
                'type',
                'in_stock',
                'featured',
                'best_seller',
                'search',
                'include_children',
                'sort',
                'per_page',
                'page',
                'ids',
            ]),
            ['prices' => $showPrices ? '1' : '0'],
            // A scoped user gets cache entries of their own, never the shared ones.
            ['scope' => CatalogScope::fingerprint($scope)]
        )));

        $cached = $this->cache->getProductList($fingerprint);

        if ($cached) {
            return response()->json($cached);
        }

        $query = Product::query()
            ->with(['category', 'subCategory', 'brand', 'primaryImage'])
            ->where('is_active', true);

        if ($scope !== null) {
            CatalogScope::restrictProducts($query, $scope);
        }

        // Filters
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('sub_category_id')) {
            $query->where('sub_category_id', $request->sub_category_id);
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }

        $rawIds = $request->input('ids');
        $requestedIds = collect(is_string($rawIds) ? explode(',', $rawIds) : [])
            ->filter(fn ($id) => ctype_digit($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->take(200)
            ->values();
        // An explicit id list never falls back to the unfiltered catalogue.
        if ($request->has('ids')) {
            $query->whereIn('id', $requestedIds);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->boolean('in_stock')) {
            $query->where('in_stock', true);
        }

        // The storefront's "Feature Product" page (?featured=1).
        // A featured variant puts its parent product on the page, because variants are listed under their parent.
        if ($request->boolean('featured')) {
            $query->where(function ($q) {
                $q->where('is_featured', true)
                    ->orWhereIn('id', Product::query()->select('parent_id')->where('is_featured', true)->whereNotNull('parent_id'));
            });
        }

        $includeChildren = filter_var($request->input('include_children', false), FILTER_VALIDATE_BOOLEAN);
        $search = $request->filled('search') ? $request->search : null;

        if (! $includeChildren) {
            if ($search) {
                // Show top-level products that match the search, OR grouped parents
                // whose children match — so a child hit always surfaces the parent.
                $query->where(function ($outerQ) use ($search) {
                    $outerQ->where(function ($q) use ($search) {
                        $q->whereNull('parent_id')
                            ->where(function ($sq) use ($search) {
                                $sq->where('name', 'like', "%{$search}%")
                                    ->orWhere('sku', 'like', "%{$search}%")
                                    ->orWhere('description', 'like', "%{$search}%");
                            });
                    })
                        ->orWhereIn('id', function ($sub) use ($search) {
                            $sub->select('parent_id')
                                ->from('products')
                                ->whereNotNull('parent_id')
                                ->where(function ($sq) use ($search) {
                                    $sq->where('name', 'like', "%{$search}%")
                                        ->orWhere('sku', 'like', "%{$search}%")
                                        ->orWhere('description', 'like', "%{$search}%");
                                });
                        });
                });
                $search = null; // handled above, skip the block below
            } else {
                $query->whereNull('parent_id');
            }
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // The storefront's "Best Sellers" page (?best_seller=1): ranked products, a ranked variant counting for its parent.
        if ($request->boolean('best_seller')) {
            $query->whereIn('id', array_keys($this->bestSellerRanks()));
        }

        // Sorting
        match ($request->get('sort', 'default')) {
            'best_selling' => $this->orderByBestSellerRank($query),
            'price_asc' => $query->orderByRaw('COALESCE(sale_price, regular_price) ASC'),
            'price_desc' => $query->orderByRaw('COALESCE(sale_price, regular_price) DESC'),
            'newest' => $query->orderBy('created_at', 'desc'),
            'name_asc' => $query->orderBy('name', 'asc'),
            default => $query->orderBy('name', 'asc'),
        };

        $perPageCap = $requestedIds->isNotEmpty() ? 200 : 100;
        $perPage = min((int) $request->get('per_page', 20), $perPageCap);
        $products = $query->paginate($perPage);

        $data = [
            'data' => $products->map(fn ($p) => $this->formatProduct($p, showPrices: $showPrices))->values()->all(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
                // Positions of the first and last product on this page; null when the page is empty.
                'from' => $products->firstItem(),
                'to' => $products->lastItem(),
            ],
        ];

        $this->cache->putProductList($fingerprint, $data);

        return response()->json($data);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $showPrices = $this->canSeePrices();
        $withSiblings = filter_var($request->input('with_siblings', false), FILTER_VALIDATE_BOOLEAN);

        // Checked before the cache: the cached detail is shared by everyone.
        $scope = CatalogScope::ids();
        if ($scope !== null) {
            $product = Product::find($id, ['id', 'parent_id', 'category_id', 'sub_category_id']);
            abort_if(! $product || ! CatalogScope::allowsProduct($product, $scope), 404);
        }

        $cacheKey = $id.($withSiblings ? ':siblings' : '').($showPrices ? ':p' : ':np');
        $cached = $this->cache->getProduct($cacheKey);

        if ($cached) {
            return response()->json($cached);
        }

        $product = Product::with([
            'category',
            'subCategory',
            'brand',
            'media',
        ])
            ->where('is_active', true)
            ->findOrFail($id);

        $groupData = ['parent' => null, 'children' => []];

        if ($product->type === 'grouped') {
            $kids = $product->children()->with('primaryImage')->get();
            $groupData['children'] = $kids->map(fn ($p) => $this->formatProduct($p, showPrices: $showPrices))->values()->all();
        } elseif ($product->parent_id && $withSiblings) {
            $parentProduct = $product->parent()->with('primaryImage')->first();
            $siblings = $parentProduct
                ? $parentProduct->children()->with('primaryImage')->where('id', '!=', $product->id)->get()
                : collect();

            $groupData['parent'] = $parentProduct ? [
                'id' => $parentProduct->id,
                'name' => $parentProduct->name,
                'slug' => $parentProduct->slug,
            ] : null;

            $groupData['children'] = $siblings->map(fn ($p) => $this->formatProduct($p, showPrices: $showPrices))->values()->all();
        }

        $data = $this->formatProduct($product, detailed: true, groupData: $groupData, showPrices: $showPrices);

        $this->cache->putProduct($cacheKey, $data);

        return response()->json($data);
    }

    /** Also used by HomepageController, so homepage products match /api/products. */
    public function formatProduct(Product $product, bool $detailed = false, array $groupData = [], bool $showPrices = true): array
    {
        $data = [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'website_url' => $product->website_url,
            'type' => $product->type,
            // The grouped product a variation belongs to (null otherwise), for grouping in carts.
            'parent_id' => $product->parent_id,
            'sku' => $product->sku,
            'regular_price' => $showPrices ? $product->regular_price : null,
            'sale_price' => $showPrices ? $product->sale_price : null,
            'current_price' => $showPrices ? $product->current_price : null,
            'on_sale' => $showPrices ? $product->isOnSale() : null,
            'prices_visible' => $showPrices,
            'in_stock' => $product->in_stock,
            'stock_quantity' => $product->manage_stock ? $product->stock_quantity : null,
            'image' => $product->primaryImage?->public_url,
            // Optional WebP variants keyed by size (64/256/512/1024) plus "original"; null when there are none.
            'image_variants' => $this->thumbnails->variantsFor($product->primaryImage),
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ] : null,
            'sub_category' => $product->subCategory ? [
                'id' => $product->subCategory->id,
                'name' => $product->subCategory->name,
                'slug' => $product->subCategory->slug,
            ] : null,
            'brand' => $product->brand ? [
                'id' => $product->brand->id,
                'name' => $product->brand->name,
                'slug' => $product->brand->slug,
            ] : null,
        ];

        if ($detailed) {
            $data['description'] = $product->description;
            $data['short_description'] = $product->short_description;
            $data['attributes'] = $product->attributes;
            $data['images'] = $product->media->map(fn ($m) => [
                'url' => $m->public_url,
                'thumbnail' => $m->thumbnail_url,
                'alt' => $m->alt,
                'is_primary' => $m->is_primary,
                'variants' => $this->thumbnails->variantsFor($m),
            ])->values()->all();
            $data['parent'] = $groupData['parent'] ?? null;
            $data['children'] = $groupData['children'] ?? [];
        }

        return $data;
    }

    public function canSeePrices(): bool
    {
        $user = auth('api')->user();

        return $user && $user->approval_status === 'approved';
    }

    /**
     * Best-seller rank per listed product id (1 = best). A ranked variant gives its parent its rank, because variants
     * are listed under their parent; the best rank wins.
     *
     * @return array<int, int>
     */
    private function bestSellerRanks(): array
    {
        $ranks = [];
        foreach (Product::query()->whereNotNull('best_seller_rank')->get(['id', 'parent_id', 'best_seller_rank']) as $product) {
            $id = (int) ($product->parent_id ?? $product->id);
            $ranks[$id] = min($ranks[$id] ?? PHP_INT_MAX, (int) $product->best_seller_rank);
        }
        asort($ranks);

        return $ranks;
    }

    /** Ranked best sellers first, in rank order, then everything else by name. */
    private function orderByBestSellerRank($query): void
    {
        $ranks = array_slice($this->bestSellerRanks(), 0, 500, true);
        if ($ranks !== []) {
            $cases = implode(' ', array_map(fn (int $id, int $rank) => "WHEN {$id} THEN {$rank}", array_keys($ranks), $ranks));
            $query->orderByRaw("CASE id {$cases} ELSE 2147483647 END");
        }
        $query->orderBy('name');
    }
}
