<?php

use App\Models\Media;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.erp.order_webhook_url' => 'https://erp.test/gateway/orders',
        'services.erp.webhook_secret'    => 'whsec-test',
    ]);
});

/** A customer, a catalog product with an image, and an order the app placed for it. */
function placedOrder(?int $erpOrderId = 501): array
{
    $user    = User::factory()->create(['approval_status' => 'approved']);
    $product = Product::create(['erp_product_id' => 'VAR#21655', 'name' => 'Wraps - Mango Peach', 'type' => 'variable', 'regular_price' => 7, 'is_active' => true, 'in_stock' => true]);
    Media::create(['mediable_type' => Product::class, 'mediable_id' => $product->id, 'url' => 'https://erp.test/uploads/img/wraps.jpg', 'disk' => 'external', 'path' => 'https://erp.test/uploads/img/wraps.jpg', 'is_primary' => true, 'sort_order' => 0]);

    $order = Order::create([
        'user_id' => $user->id, 'invoice_no' => 'ORD000007', 'order_key' => 'order_DetailsTest001', 'erp_order_id' => $erpOrderId,
        'status' => 'pending', 'payment_status' => 'due', 'currency' => 'USD', 'line_total' => 14, 'total' => 14,
        'billing_first_name' => 'Jane', 'billing_last_name' => 'Doe', 'billing_email' => 'jane@example.com', 'billing_phone' => '4785550100',
        'shipping_first_name' => 'Jane', 'shipping_last_name' => 'Doe', 'shipping_city' => 'Centerville',
    ]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'erp_product_id' => 'VAR#21655', 'name' => 'Wraps - Mango Peach', 'sku' => 'BMW-MP', 'quantity' => 2, 'unit_price' => 7, 'unit_tax' => 0]);

    return [$user, $product, $order];
}

/** What the ERP's GET /gateway/orders/{id} returns after staff edited the sale. */
function erpSale(): array
{
    return [
        'erp_order_id' => 501, 'invoice_no' => '0042', 'status' => 'shipped', 'payment_status' => 'due',
        'transaction_date' => '2026-10-07T10:20:53-04:00', 'due_date' => '2026-11-06',
        'line_total' => 26.0, 'discount_total' => 2.6, 'shipping_total' => 5.0, 'total_tax' => 0.0, 'total' => 28.4,
        'amount_paid' => 8.0, 'amount_due' => 20.4, 'customer_note' => 'Leave at the back door',
        'billing' => ['name' => 'Jane Doe', 'company' => null, 'address_1' => '1 Main St', 'address_2' => null, 'city' => 'Centerville', 'state' => 'GA', 'postcode' => '31028', 'country' => 'US'],
        'shipping' => ['name' => 'Jane Doe', 'company' => null, 'address_1' => '9 Dock Rd', 'address_2' => null, 'city' => 'Macon', 'state' => null, 'postcode' => '31201', 'country' => 'US'],
        'items' => [
            ['id' => 9001, 'erp_product_id' => 'VAR#21655', 'name' => 'Wraps - Mango Peach', 'sku' => 'BMW-MP', 'quantity' => 3.0, 'unit_price' => 7.0, 'unit_tax' => 0.0, 'total' => 21.0],
            ['id' => 9002, 'erp_product_id' => '999', 'name' => 'Added by staff', 'sku' => null, 'quantity' => 1.0, 'unit_price' => 5.0, 'unit_tax' => 0.0, 'total' => 5.0],
        ],
    ];
}

it('shows a synced order as the ERP has it now', function () {
    [$user, $product, $order] = placedOrder();
    Http::fake(['erp.test/*' => Http::response(erpSale())]);

    $this->actingAs($user, 'api')->getJson("/api/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('source', 'erp')
        ->assertJsonPath('invoice_no', 'ORD000007')
        ->assertJsonPath('erp_invoice_no', '0042')
        ->assertJsonPath('status', 'shipped')
        ->assertJsonPath('total', '28.40')
        ->assertJsonPath('discount_total', '2.60')
        ->assertJsonPath('due_date', '2026-11-06')
        ->assertJsonPath('amount_paid', '8.00')
        ->assertJsonPath('amount_due', '20.40')
        ->assertJsonPath('shipping.city', 'Macon')
        ->assertJsonPath('billing.email', 'jane@example.com')
        ->assertJsonPath('items.0.product_id', $product->id)
        ->assertJsonPath('items.0.image', 'https://erp.test/uploads/img/wraps.jpg')
        ->assertJsonPath('items.0.quantity', 3)
        ->assertJsonPath('items.0.unit_price', '7.00')
        ->assertJsonPath('items.1.product_id', null)
        ->assertJsonPath('items.1.name', 'Added by staff');

    $order->refresh();
    expect($order->status)->toBe('shipped')->and((string) $order->total)->toBe('28.40');

    Http::assertSent(function (Request $request) {
        $timestamp = $request->header('X-Webhook-Timestamp')[0];

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://erp.test/gateway/orders/501?order_key=order_DetailsTest001')
            && $request->header('X-Webhook-Secret')[0] === hash_hmac('sha256', "{$timestamp}\n501\norder_DetailsTest001", 'whsec-test');
    });
});

it("falls back to the gateway's copy when the ERP can't be reached", function (Closure $erp) {
    [$user, $product, $order] = placedOrder();
    Http::fake($erp);

    $this->actingAs($user, 'api')->getJson("/api/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('source', 'gateway')
        ->assertJsonPath('total', '14.00')
        ->assertJsonPath('items.0.quantity', 2)
        ->assertJsonPath('items.0.product_id', $product->id)
        ->assertJsonPath('items.0.image', 'https://erp.test/uploads/img/wraps.jpg');

    expect($order->fresh()->status)->toBe('pending');
})->with([
    'server error' => fn () => fn () => Http::response('down', 500),
    'timeout'      => fn () => fn () => throw new ConnectionException('timed out'),
]);

it('never asks the ERP about an order it does not have yet', function () {
    [$user, , $order] = placedOrder(erpOrderId: null);
    Http::fake();

    $this->actingAs($user, 'api')->getJson("/api/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('source', 'gateway');

    Http::assertNothingSent();
});

it("hides another customer's order", function () {
    [, , $order] = placedOrder();
    Http::fake();

    $stranger = User::factory()->create(['approval_status' => 'approved']);
    $this->actingAs($stranger, 'api')->getJson("/api/orders/{$order->id}")->assertNotFound();

    Http::assertNothingSent();
});
