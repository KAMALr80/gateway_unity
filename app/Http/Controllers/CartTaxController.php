<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Services\ErpTaxService;
use App\Support\CatalogScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * POST /api/cart/tax: the tax a cart would be charged, for the cart screen to show each time it opens.
 *
 * The ERP owns the tax rules, so it is asked live (ErpTaxService). It charges a line's rule only when the
 * customer's state matches the rule's, and that state is the customer's ERP account state, else the state of
 * the address being ordered to. With neither, `state_required` is true and the order would be refused, so the
 * client should ask for a state before checkout.
 *
 * Quotes `items` when sent (the app keeps its cart on the phone), else the account's server cart. Lines that
 * can't be ordered (gone, a group, out of stock, outside the user's catalog) are left out, as at checkout.
 */
class CartTaxController extends Controller
{
    public function __invoke(Request $request, ErpTaxService $erp): JsonResponse
    {
        $data = $request->validate([
            'items'              => ['sometimes', 'array', 'max:1000'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity'   => ['required', 'integer', 'min:1', 'max:99999'],
            'state'              => ['sometimes', 'nullable', 'string', 'max:100'],
            'address_id'         => ['sometimes', 'nullable', 'integer'],
        ]);

        /** @var User $user */
        $user  = auth('api')->user();
        $lines = $this->orderableLines($user, $this->wanted($user, $data));

        $addressState = $this->addressState($user, $data);
        $accountState = self::text($user->state);

        $erpLines = $lines->filter(fn (array $line) => $line['product']->erp_product_id)
            ->map(fn (array $line) => ['product_id' => (string) $line['product']->erp_product_id, 'quantity' => $line['quantity']])
            ->values()->all();

        $quote = $erpLines === [] ? null : $erp->quote($user, $erpLines, $addressState);
        // Nothing to ask about is a known answer (no tax); an unreachable ERP is not.
        $available = $erpLines === [] || $quote !== null;

        $taxes = collect($quote['lines'] ?? [])->keyBy(fn ($line) => (string) ($line['product_id'] ?? ''));

        $items = $lines->map(function (array $line) use ($taxes, $available) {
            $product = $line['product'];
            $price   = (float) $product->current_price;
            $tax     = $taxes->get((string) $product->erp_product_id);

            return [
                'product_id' => $product->id,
                'quantity'   => $line['quantity'],
                'price'      => $price,
                'line_total' => round($price * $line['quantity'], 2),
                'tax'        => $available ? round((float) ($tax['tax'] ?? 0), 2) : null,
                'tax_id'     => $tax['tax_id'] ?? null,
                'tax_name'   => $tax['tax_name'] ?? null,
            ];
        })->values();

        if ($quote !== null) {
            $state  = self::text($quote['state']);
            $source = match ($quote['state_source']) {
                'customer' => 'account',
                'address'  => 'address',
                default    => null,
            };
        } else {
            $state  = $accountState ?? $addressState;
            $source = $accountState !== null ? 'account' : ($addressState !== null ? 'address' : null);
        }

        $subtotal = round($items->sum('line_total'), 2);
        $totalTax = $available ? round($items->sum('tax'), 2) : null;

        $payload = [
            'data' => [
                'available'      => $available,
                'state'          => $state,
                'state_source'   => $source,
                'state_required' => $state === null,
                'items'          => $items->all(),
                'subtotal'       => $subtotal,
                'total_tax'      => $totalTax,
                'total'          => $totalTax === null ? null : round($subtotal + $totalTax, 2),
            ],
        ];

        if ($state === null) {
            $payload['message'] = 'Add your state to your address. Tax depends on it, and an order needs one.';
        } elseif (! $available) {
            $payload['message'] = 'Tax could not be worked out right now. It will be added when your order is processed.';
        }

        return response()->json($payload);
    }

    /** product_id => quantity: the request's items, else the server cart. */
    private function wanted(User $user, array $data): Collection
    {
        if (array_key_exists('items', $data)) {
            return collect($data['items'])
                ->groupBy('product_id')
                ->map(fn (Collection $rows) => (int) $rows->sum('quantity'));
        }

        return CartItem::where('user_id', $user->id)->pluck('quantity', 'product_id')->map(fn ($qty) => (int) $qty);
    }

    /** @return Collection<int, array{product: Product, quantity: int}> */
    private function orderableLines(User $user, Collection $wanted): Collection
    {
        if ($wanted->isEmpty()) {
            return collect();
        }

        $query = Product::whereIn('id', $wanted->keys())
            ->where('is_active', true)
            ->where('in_stock', true)
            ->where('type', '!=', 'grouped');

        if (($scope = CatalogScope::ids()) !== null) {
            CatalogScope::restrictProducts($query, $scope);
        }

        $products = $query->get()->keyBy('id');

        return $wanted
            ->filter(fn (int $qty, $id) => $qty > 0 && $products->has($id))
            ->map(fn (int $qty, $id) => ['product' => $products->get($id), 'quantity' => $qty])
            ->values();
    }

    /** The state of the address being ordered to: `state`, else that of the user's `address_id`. */
    private function addressState(User $user, array $data): ?string
    {
        if ($state = self::text($data['state'] ?? null)) {
            return $state;
        }

        if (! empty($data['address_id'])) {
            return self::text(Address::where('user_id', $user->id)->whereKey($data['address_id'])->value('state'));
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
