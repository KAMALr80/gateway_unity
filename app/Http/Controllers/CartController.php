<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\Product;
use App\Support\CatalogScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Account-level cart. The authenticated user always comes from the API guard, never from the request body,
 * and prices/stock/availability always come from the products table.
 *
 * Every endpoint returns the full, validated cart so clients (any device) can simply replace their state:
 *   { data: { items: [...], item_count, subtotal, prices_visible }, notices: [string] }
 */
class CartController extends Controller
{
    /** Re-run a cart transaction this many times if the database reports a deadlock. */
    private const TRANSACTION_ATTEMPTS = 5;

    public function index(): JsonResponse
    {
        return $this->cartResponse();
    }

    /** Add a quantity of a product (increments an existing line). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity'   => ['required', 'integer', 'min:1', 'max:' . CartItem::MAX_QUANTITY],
        ]);

        $product = Product::find($data['product_id']);
        if ($error = $this->unavailableReason($product)) {
            return $this->cartResponse(status: 422, message: $error);
        }

        $notices = [];
        DB::transaction(function () use ($product, $data, &$notices) {
            $notices = [];
            $item    = $this->lockLine($product->id);
            $wanted  = $item->quantity + $data['quantity'];
            $allowed = min($wanted, $this->maxQuantity($product));
            if ($allowed < $wanted) {
                $notices[] = "{$product->name}: quantity limited to {$allowed}.";
            }
            $item->update(['quantity' => $allowed]);
        }, self::TRANSACTION_ATTEMPTS);

        return $this->cartResponse($notices);
    }

    /** Set the exact quantity of a line; 0 removes it. */
    public function update(Request $request, int $productId): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:' . CartItem::MAX_QUANTITY],
        ]);

        $quantity = (int) $data['quantity'];
        if ($quantity === 0) {
            return $this->destroy($productId);
        }

        $product = Product::find($productId);
        if ($error = $this->unavailableReason($product)) {
            // An out-of-stock line may still be reduced (never increased), so the user can tidy the cart.
            $current = CartItem::where('user_id', auth('api')->id())->where('product_id', $productId)->value('quantity');
            $canReduce = $product && $product->is_active && $product->type !== 'grouped' && $current !== null && $quantity <= $current;
            if (! $canReduce) {
                return $this->cartResponse(status: 422, message: $error);
            }
        }
        $data['quantity'] = $quantity;

        $notices = [];
        DB::transaction(function () use ($product, $data, &$notices) {
            $notices = [];
            $item    = $this->lockLine($product->id);
            $allowed = min($data['quantity'], $this->maxQuantity($product));
            if ($allowed < $data['quantity']) {
                $notices[] = "{$product->name}: quantity limited to {$allowed}.";
            }
            $item->update(['quantity' => $allowed]);
        }, self::TRANSACTION_ATTEMPTS);

        return $this->cartResponse($notices);
    }

    public function destroy(int $productId): JsonResponse
    {
        CartItem::where('user_id', auth('api')->id())->where('product_id', $productId)->delete();

        return $this->cartResponse();
    }

    public function clear(): JsonResponse
    {
        CartItem::where('user_id', auth('api')->id())->delete();

        return $this->cartResponse();
    }

    /**
     * Merge a guest (logged-out) cart into the account cart after login.
     * Quantities of the same product are added together, then capped by stock and MAX_QUANTITY;
     * unavailable products are skipped with a notice. `merge_id` makes a retried request a no-op.
     */
    public function merge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'merge_id'           => ['nullable', 'string', 'max:64'],
            'items'              => ['present', 'array', 'max:200'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity'   => ['required', 'integer', 'min:1'],
        ]);

        $userId   = auth('api')->id();
        $mergeKey = empty($data['merge_id']) ? null : "cart-merge:{$userId}:{$data['merge_id']}";
        if ($mergeKey && ! Cache::add($mergeKey, true, now()->addDay())) {
            return $this->cartResponse(); // already merged (client retry)
        }

        $incoming = collect($data['items'])
            ->groupBy('product_id')
            ->map(fn (Collection $rows) => min((int) $rows->sum('quantity'), CartItem::MAX_QUANTITY));

        $products = Product::whereIn('id', $incoming->keys())->get()->keyBy('id');
        $notices  = [];

        try {
            DB::transaction(function () use ($incoming, $products, &$notices) {
                $notices = [];
                $this->mergeLines($incoming, $products, $notices);
            }, self::TRANSACTION_ATTEMPTS);
        } catch (\Throwable $e) {
            // Let the client retry the same merge_id instead of silently dropping the guest cart.
            if ($mergeKey) {
                Cache::forget($mergeKey);
            }
            throw $e;
        }

        return $this->cartResponse($notices);
    }

    private function mergeLines(Collection $incoming, Collection $products, array &$notices): void
    {
        foreach ($incoming as $productId => $quantity) {
            $product = $products->get($productId);
            if ($error = $this->unavailableReason($product)) {
                $notices[] = ($product?->name ?? 'A product') . ' could not be added: ' . lcfirst($error);
                continue;
            }

            $item    = $this->lockLine($product->id);
            $wanted  = $item->quantity + $quantity;
            $allowed = min($wanted, $this->maxQuantity($product));
            if ($allowed < $wanted) {
                $notices[] = "{$product->name}: quantity limited to {$allowed}.";
            }
            $item->update(['quantity' => $allowed]);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Returns the user's line for a product, locked for update, creating it (quantity 0) if missing.
     * The unique (user_id, product_id) index means simultaneous requests can never create duplicate lines;
     * the row lock serialises quantity changes. An existing line is locked directly (the common case);
     * creating a new one can still deadlock under heavy concurrency on InnoDB, which is why every caller
     * runs inside DB::transaction(..., TRANSACTION_ATTEMPTS) - Laravel re-runs the closure on a deadlock.
     */
    private function lockLine(int $productId): CartItem
    {
        $userId = auth('api')->id();
        $line   = fn () => CartItem::where('user_id', $userId)->where('product_id', $productId)->lockForUpdate();

        if ($existing = $line()->first()) {
            return $existing;
        }

        $now = now();
        CartItem::insertOrIgnore([
            'user_id'    => $userId,
            'product_id' => $productId,
            'quantity'   => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $line()->firstOrFail();
    }

    /** Why a product cannot be put in the cart, or null when it can. Mirrors what OrderController accepts. */
    private function unavailableReason(?Product $product): ?string
    {
        if (! $product || ! $product->is_active) {
            return 'This product is no longer available.';
        }
        // A user limited to part of the catalog can't order outside it, so it can't be carted either.
        if (($scope = CatalogScope::ids()) !== null && ! CatalogScope::allowsProduct($product, $scope)) {
            return 'This product is no longer available.';
        }
        if ($product->type === 'grouped') {
            return 'Please choose a specific variant of this product.';
        }
        if (! $product->in_stock) {
            return 'This product is out of stock.';
        }

        return null;
    }

    /**
     * Highest quantity allowed for a line. Availability is decided by `in_stock` (as at checkout);
     * the ERP stock count only caps the quantity when it is a positive, managed number.
     */
    private function maxQuantity(Product $product): int
    {
        if ($product->manage_stock && $product->stock_quantity > 0) {
            return min(CartItem::MAX_QUANTITY, (int) $product->stock_quantity);
        }

        return CartItem::MAX_QUANTITY;
    }

    private function canSeePrices(): bool
    {
        $user = auth('api')->user();

        return $user && $user->approval_status === 'approved';
    }

    /**
     * Re-validates every stored line against the current catalog (removing deleted/disabled products,
     * capping quantities to current stock) and returns the authoritative cart.
     */
    private function cartResponse(array $notices = [], int $status = 200, ?string $message = null): JsonResponse
    {
        $userId     = auth('api')->id();
        $showPrices = $this->canSeePrices();

        $lines = CartItem::where('user_id', $userId)
            ->with(['product' => fn ($q) => $q->with(['primaryImage', 'parent'])])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $items = [];
        foreach ($lines as $line) {
            $product = $line->product;

            if (! $product || ! $product->is_active || $product->type === 'grouped' || $line->quantity < 1) {
                if ($product && $line->quantity >= 1) {
                    $notices[] = "{$product->name} is no longer available and was removed from your cart.";
                }
                $line->delete();
                continue;
            }

            $max = $this->maxQuantity($product);
            if ($line->quantity > $max) {
                $line->update(['quantity' => $max]);
                $notices[] = "{$product->name}: quantity reduced to {$max} (available stock).";
            }

            $items[] = [
                'product_id'     => $product->id,
                'name'           => $product->name,
                'sku'            => $product->sku,
                'image'          => $product->primaryImage?->public_url,
                'image_variants' => app(\App\Services\MediaThumbnails::class)->variantsFor($product->primaryImage),
                'quantity'       => $line->quantity,
                'price'          => $showPrices ? (float) $product->current_price : null,
                'regular_price'  => $showPrices ? (float) $product->regular_price : null,
                'on_sale'        => $showPrices ? $product->isOnSale() : null,
                'in_stock'       => (bool) $product->in_stock,
                'stock_quantity' => $product->manage_stock ? $product->stock_quantity : null,
                'max_quantity'   => $max,
                'parent_id'      => $product->parent_id,
                'parent_name'    => $product->parent?->name,
            ];
        }

        $subtotal = $showPrices
            ? round(collect($items)->where('in_stock', true)->sum(fn ($i) => $i['price'] * $i['quantity']), 2)
            : null;

        $payload = [
            'data' => [
                'items'          => $items,
                'item_count'     => collect($items)->sum('quantity'),
                'subtotal'       => $subtotal,
                'prices_visible' => $showPrices,
            ],
            'notices' => array_values(array_unique($notices)),
        ];
        if ($message !== null) {
            $payload['message'] = $message;
        }

        return response()->json($payload, $status);
    }
}
