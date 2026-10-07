<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\WishlistItem;
use App\Support\CatalogScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    public function index(): JsonResponse
    {
        $items = WishlistItem::where('user_id', auth('api')->id())
            ->with(['product' => fn($q) => $q->with('primaryImage')->where('is_active', true)])
            ->latest()
            ->get()
            ->filter(fn($item) => $item->product !== null)
            ->filter(fn($item) => ($scope = CatalogScope::ids()) === null || CatalogScope::allowsProduct($item->product, $scope))
            ->map(fn($item) => [
                'id'         => $item->id,
                'added_at'   => $item->created_at->toIso8601String(),
                'product'    => [
                    'id'       => $item->product->id,
                    'name'     => $item->product->name,
                    'slug'     => $item->product->slug,
                    'sku'      => $item->product->sku,
                    'in_stock' => $item->product->in_stock,
                    'image'    => $item->product->primaryImage?->public_url,
                ],
            ])
            ->values();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        $query = Product::where('id', $request->product_id)->where('is_active', true);
        if (($scope = CatalogScope::ids()) !== null) {
            CatalogScope::restrictProducts($query, $scope);
        }
        $product = $query->firstOrFail();

        $item = WishlistItem::firstOrCreate([
            'user_id'    => auth('api')->id(),
            'product_id' => $product->id,
        ]);

        return response()->json([
            'message' => $item->wasRecentlyCreated ? 'Added to wishlist.' : 'Already in wishlist.',
            'data'    => ['id' => $item->id, 'product_id' => $item->product_id],
        ], $item->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = WishlistItem::where('id', $id)
            ->where('user_id', auth('api')->id())
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Wishlist item not found.'], 404);
        }

        return response()->json(['message' => 'Removed from wishlist.']);
    }

    public function destroyByProduct(int $productId): JsonResponse
    {
        $deleted = WishlistItem::where('product_id', $productId)
            ->where('user_id', auth('api')->id())
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Product not in wishlist.'], 404);
        }

        return response()->json(['message' => 'Removed from wishlist.']);
    }
}
