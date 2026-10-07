<?php

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function prefixedIdProduct(string $erpId, string $type = 'variable'): Product
{
    return Product::create([
        'erp_product_id' => $erpId,
        'name'           => "Product {$erpId}",
        'type'           => $type,
        'regular_price'  => 12.50,
        'is_active'      => true,
        'in_stock'       => true,
    ]);
}

function unityOrderPayload(array $items): array
{
    $address = [
        'first_name' => 'Jane', 'last_name' => 'Doe', 'address_1' => '1 Main St',
        'city' => 'Centerville', 'postcode' => '31028', 'country' => 'US',
    ];

    $payload = ['items' => $items, 'billing_email' => 'jane@example.com', 'billing_phone' => '4785550100'];
    foreach ($address as $field => $value) {
        $payload["billing_{$field}"]  = $value;
        $payload["shipping_{$field}"] = $value;
    }

    return $payload;
}

it('places an order for a variation whose ERP id is not numeric', function () {
    Queue::fake();
    $user      = User::factory()->create(['approval_status' => 'approved']);
    $variation = prefixedIdProduct('VAR#21655');
    $simple    = prefixedIdProduct('14053', 'simple');

    $payload = unityOrderPayload([
        ['product_id' => $variation->id, 'quantity' => 2],
        ['product_id' => $simple->id, 'quantity' => 1],
    ]);
    // Sent by the app; not part of the contract and ignored.
    $payload['address_id']  = 99;
    $payload['customer_id'] = 99;

    $this->actingAs($user, 'api')->postJson('/api/orders', $payload)->assertCreated();

    expect(OrderItem::pluck('erp_product_id')->sort()->values()->all())->toBe(['14053', 'VAR#21655']);
});

it('stores the website link sent by the ERP and returns it to the app', function () {
    config(['services.erp.internal_key' => 'test-key']);

    $this->withHeader('X-Internal-Key', 'test-key')->postJson('/internal/products/sync', ['products' => [
        ['erp_product_id' => 'GROUP#1', 'name' => 'Straws', 'type' => 'grouped', 'regular_price' => 5, 'website_url' => 'https://unitywholesales.com/?p=119925'],
        ['erp_product_id' => 'VAR#7', 'parent_erp_id' => 'GROUP#1', 'name' => 'Straws - Jumbo', 'type' => 'variable', 'regular_price' => 5, 'website_url' => 'https://unitywholesales.com/?p=119925'],
        ['erp_product_id' => '99', 'name' => 'Not on the website', 'regular_price' => 3],
    ]])->assertOk()->assertJsonPath('errors', []);

    $variation = Product::where('erp_product_id', 'VAR#7')->first();
    $this->getJson("/api/products/{$variation->id}")
        ->assertOk()
        ->assertJsonPath('website_url', 'https://unitywholesales.com/?p=119925');

    expect(Product::where('erp_product_id', '99')->value('website_url'))->toBeNull();
});
