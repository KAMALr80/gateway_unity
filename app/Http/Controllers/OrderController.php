<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderRequest;
use App\Jobs\SendOrderToErp;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Services\ErpOrderService;
use App\Services\SequenceService;
use App\Support\CatalogScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = Order::where('user_id', auth('api')->id())
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'data' => $orders->map(fn($o) => $this->formatOrder($o)),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
                'last_page'    => $orders->lastPage(),
            ],
        ]);
    }

    /**
     * An optional Idempotency-Key header makes placing an order safe to repeat: the storefront sends one key
     * per checkout attempt, so a network retry, a second tab or a double submit returns the order that key
     * already created instead of a new one. Without the header the request behaves as before.
     */
    public function store(OrderRequest $request): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || $key === '') {
            return $this->placeOrder($request);
        }
        if (! preg_match('/^[A-Za-z0-9_-]{8,100}$/', $key)) {
            return response()->json(['message' => 'The Idempotency-Key header is invalid.'], 422);
        }

        $cacheKey = 'order-idempotency:'.auth('api')->id().':'.hash('sha256', $key);
        $lock = Cache::lock($cacheKey.':lock', 30);
        if (! $lock->get()) {
            return response()->json(['message' => 'This order is already being placed. Please wait a moment.'], 409);
        }

        try {
            $existing = ($id = Cache::get($cacheKey)) ? Order::where('user_id', auth('api')->id())->find($id) : null;
            if ($existing) {
                return response()->json(['message' => 'Order placed successfully', 'data' => $this->formatOrder($existing)], 201);
            }

            $response = $this->placeOrder($request);
            if ($response->getStatusCode() === 201) {
                Cache::put($cacheKey, $response->getData(true)['data']['id'], now()->addDay());
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function placeOrder(OrderRequest $request): JsonResponse
    {
        $user = auth('api')->user();
        $data = $request->validated();

        // Resolve products and calculate totals
        $productIds = collect($data['items'])->pluck('product_id');
        $productQuery = Product::whereIn('id', $productIds)
            ->where('is_active', true)
            ->where('in_stock', true)
            // A grouped product only picks a variation (as in the cart); it has no price of its own.
            ->where('type', '!=', 'grouped');

        // A user limited to part of the catalog can't order outside it.
        if (($scope = CatalogScope::ids()) !== null) {
            CatalogScope::restrictProducts($productQuery, $scope);
        }

        $products = $productQuery->get()->keyBy('id');

        // Validate all requested products exist and are available
        foreach ($data['items'] as $item) {
            if (!$products->has($item['product_id'])) {
                return response()->json([
                    'message' => "Product ID {$item['product_id']} is unavailable or out of stock",
                ], 422);
            }
        }

        // Build line items and calculate line_total
        $lineItems = [];
        $lineTotal = 0;

        foreach ($data['items'] as $item) {
            $product   = $products->get($item['product_id']);
            $unitPrice = $product->current_price;
            $unitTax   = 0;                                    // extend here if you have tax logic
            $lineTotal += $unitPrice * $item['quantity'];

            $lineItems[] = [
                'product_id'     => $product->id,
                'erp_product_id' => $product->erp_product_id,
                'name'           => $product->name,
                'sku'            => $product->sku,
                'quantity'       => $item['quantity'],
                'unit_price'     => $unitPrice,
                'unit_tax'       => $unitTax,
            ];
        }

        // Totals are never taken from the client: the storefront has no discount, shipping or tax engine
        // (shipping is quoted later and prices are net of tax), so these stay 0 until one exists server-side.
        // Accepting client values here allowed a buyer to lower the order total.
        $discountTotal  = 0;
        $shippingTotal  = 0;
        $totalTax       = 0;
        $total          = $lineTotal - $discountTotal + $shippingTotal + $totalTax;

        $sequence = app(SequenceService::class);
        $invoiceNo = 'ORD' . str_pad($sequence->next('invoice_no'), 6, '0', STR_PAD_LEFT);

        try {
            $order = DB::transaction(function () use (
                $invoiceNo, $data, $user, $lineItems,
                $lineTotal, $discountTotal, $shippingTotal, $totalTax, $total
            ) {
                $order = Order::create([
                    'invoice_no'           => $invoiceNo,
                    'order_key'            => 'order_' . Str::random(13),
                    'created_via'          => 'rest-api',
                    'transaction_date'     => now(),
                    'user_id'              => $user->id,
                    'erp_customer_id'      => $user->erp_contact_id,
                    'status'               => 'pending',
                    'payment_status'       => 'due',
                    'currency'             => 'USD', // catalog prices are USD
                    'line_total'           => $lineTotal,
                    'discount_total'       => $discountTotal,
                    'shipping_total'       => $shippingTotal,
                    'total_tax'            => $totalTax,
                    'total'                => $total,
                    'customer_note'        => $data['customer_note']  ?? null,
                    'customer_ip_address'  => request()->ip(),
                    'customer_user_agent'  => request()->userAgent(),
                    'meta_data'            => $data['meta_data']      ?? null,
                    'billing_first_name'   => $data['billing_first_name'],
                    'billing_last_name'    => $data['billing_last_name'],
                    'billing_company'      => $data['billing_company']  ?? null,
                    'billing_address_1'    => $data['billing_address_1'],
                    'billing_address_2'    => $data['billing_address_2'] ?? null,
                    'billing_city'         => $data['billing_city'],
                    'billing_state'        => $data['billing_state']    ?? null,
                    'billing_postcode'     => $data['billing_postcode'],
                    'billing_country'      => $data['billing_country'],
                    'billing_email'        => $data['billing_email'],
                    'billing_phone'        => $data['billing_phone'],
                    'shipping_first_name'  => $data['shipping_first_name'],
                    'shipping_last_name'   => $data['shipping_last_name'],
                    'shipping_company'     => $data['shipping_company']  ?? null,
                    'shipping_address_1'   => $data['shipping_address_1'],
                    'shipping_address_2'   => $data['shipping_address_2'] ?? null,
                    'shipping_city'        => $data['shipping_city'],
                    'shipping_state'       => $data['shipping_state']    ?? null,
                    'shipping_postcode'    => $data['shipping_postcode'],
                    'shipping_country'     => $data['shipping_country'],
                    'sync_status'          => 'pending',
                ]);

                $order->items()->createMany($lineItems);

                // The ordered products leave the account cart in the same transaction, so every device sees
                // the emptied cart even if the client never gets to clear it.
                CartItem::where('user_id', $user->id)
                    ->whereIn('product_id', array_column($lineItems, 'product_id'))
                    ->delete();

                return $order;
            });
        } catch (\Throwable $e) {
            Log::emergency('Failed to place order', [
                'error' => $e->getMessage(),
                'stack' => $e->getTraceAsString(),
                'user_id' => auth('api')->id(),
                'request_data' => $data,
            ]);
            return response()->json(['message' => 'Failed to place order'], 500);
        }

        // The order is committed at this point. A failure to queue the ERP sync must not be reported to the
        // buyer as a failed order (they would place it again); it is logged and the order stays 'pending'
        // so it can be re-sent.
        try {
            SendOrderToErp::dispatch($order);
        } catch (\Throwable $e) {
            Log::error('Order placed but ERP sync could not be queued', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Order placed successfully',
            'data'    => $this->formatOrder($order),
        ], 201);
    }

    /**
     * An order the ERP has is shown as the ERP has it now: staff may have
     * changed lines, prices or shipping since it was placed, and payments are
     * recorded there. If the ERP can't be reached, the gateway's copy is shown
     * instead. "source" says which: "erp" or "gateway".
     */
    public function show(int $id, ErpOrderService $erp): JsonResponse
    {
        $order = Order::with('items.product.primaryImage')
            ->where('user_id', auth('api')->id())
            ->findOrFail($id);

        $live = $order->erp_order_id ? $erp->fetchOrder($order) : null;

        if ($live) {
            // Keep the list (index) in step with what the detail now shows.
            $order->update([
                'status'         => $live['status'] ?? $order->status,
                'payment_status' => $live['payment_status'] ?? $order->payment_status,
                'total'          => $live['total'] ?? $order->total,
            ] + array_intersect_key($live, array_flip(['erp_status', 'erp_shipping_status', 'erp_payment_status'])));

            return response()->json($this->formatErpOrder($order, $live));
        }

        return response()->json($this->formatOrder($order, detailed: true) + ['source' => 'gateway']);
    }

    /**
     * The ERP's sale in the same shape as formatOrder(), plus what only the
     * ERP knows: its invoice number, the due date from the customer's pay
     * term, and what's been paid. Items are linked back to the catalog by
     * their ERP product id, for "buy again" and images.
     */
    private function formatErpOrder(Order $order, array $live): array
    {
        $base  = $this->formatOrder($order, detailed: true);
        $money = fn ($value) => $value === null ? null : number_format((float) $value, 2, '.', '');
        $count = fn ($value) => floor((float) $value) == (float) $value ? (int) $value : (float) $value;

        $products = Product::with('primaryImage')
            ->whereIn('erp_product_id', collect($live['items'])->pluck('erp_product_id')->filter()->unique())
            ->get()
            ->keyBy('erp_product_id');

        $address = fn (?array $fromErp, array $ours) => $fromErp
            ? array_merge($ours, array_filter($fromErp, fn ($value) => $value !== null))
            : $ours;

        return array_merge($base, [
            'status'           => $live['status'] ?? $base['status'],
            'payment_status'   => $live['payment_status'] ?? $base['payment_status'],
            'transaction_date' => $live['transaction_date'] ?? $base['transaction_date'],
            'line_total'       => $money($live['line_total'] ?? $base['line_total']),
            'discount_total'   => $money($live['discount_total'] ?? $base['discount_total']),
            'shipping_total'   => $money($live['shipping_total'] ?? $base['shipping_total']),
            'total_tax'        => $money($live['total_tax'] ?? $base['total_tax']),
            'total'            => $money($live['total'] ?? $base['total']),
            'customer_note'    => $live['customer_note'] ?? $base['customer_note'],
            'billing'          => $address($live['billing'] ?? null, $base['billing']),
            'shipping'         => $address($live['shipping'] ?? null, $base['shipping']),
            'items'            => collect($live['items'])->map(function (array $item) use ($products, $money, $count) {
                $product = $products->get($item['erp_product_id'] ?? null);

                return [
                    'id'         => $item['id'] ?? null,
                    'product_id' => $product?->id,
                    'image'      => $product?->primaryImage?->public_url,
                    'image_variants' => app(\App\Services\MediaThumbnails::class)->variantsFor($product?->primaryImage),
                    'name'       => $item['name'] ?? $product?->name,
                    'sku'        => $item['sku'] ?? null,
                    'quantity'   => $count($item['quantity'] ?? 0),
                    'unit_price' => $money($item['unit_price'] ?? 0),
                    'unit_tax'   => $money($item['unit_tax'] ?? 0),
                    'total'      => $money($item['total'] ?? 0),
                ];
            })->values()->all(),
            'erp_invoice_no'   => $live['invoice_no'] ?? null,
            'due_date'         => $live['due_date'] ?? null,
            'amount_paid'      => $money($live['amount_paid'] ?? null),
            'amount_due'       => $money($live['amount_due'] ?? null),
            'source'           => 'erp',
        ]);
    }

    private function formatOrder(Order $order, bool $detailed = false): array
    {
        $data = [
            'id'             => $order->id,
            'invoice_no'     => $order->invoice_no,
            'status'         => $order->status,
            'payment_status' => $order->payment_status,
            // The ERP's own statuses, unmapped; null until the order is in the ERP.
            'erp_status'          => $order->erp_status,
            'erp_shipping_status' => $order->erp_shipping_status,
            'erp_payment_status'  => $order->erp_payment_status,
            'sync_status'    => $order->sync_status,
            'currency'       => $order->currency,
            'total'          => $order->total,
            'created_at'     => $order->created_at->toIso8601String(),
        ];

        if ($detailed) {
            $data['order_key']       = $order->order_key;
            $data['transaction_date']= $order->transaction_date?->toIso8601String();
            $data['line_total']      = $order->line_total;
            $data['discount_total']  = $order->discount_total;
            $data['shipping_total']  = $order->shipping_total;
            $data['total_tax']       = $order->total_tax;
            $data['customer_note']   = $order->customer_note;
            $data['billing']         = [
                'name'      => $order->billing_full_name,
                'company'   => $order->billing_company,
                'address_1' => $order->billing_address_1,
                'address_2' => $order->billing_address_2,
                'city'      => $order->billing_city,
                'state'     => $order->billing_state,
                'postcode'  => $order->billing_postcode,
                'country'   => $order->billing_country,
                'email'     => $order->billing_email,
                'phone'     => $order->billing_phone,
            ];
            $data['shipping']        = [
                'name'      => $order->shipping_full_name,
                'company'   => $order->shipping_company,
                'address_1' => $order->shipping_address_1,
                'address_2' => $order->shipping_address_2,
                'city'      => $order->shipping_city,
                'state'     => $order->shipping_state,
                'postcode'  => $order->shipping_postcode,
                'country'   => $order->shipping_country,
            ];
            $data['items']           = $order->items->map(fn($item) => [
                'id'         => $item->id,
                'product_id' => $item->product_id,
                'image'      => $item->product?->primaryImage?->public_url,
                'image_variants' => app(\App\Services\MediaThumbnails::class)->variantsFor($item->product?->primaryImage),
                'name'       => $item->name,
                'sku'        => $item->sku,
                'quantity'   => $item->quantity,
                'unit_price' => $item->unit_price,
                'unit_tax'   => $item->unit_tax,
                'total'      => $item->total,
            ]);
        }

        return $data;
    }
}