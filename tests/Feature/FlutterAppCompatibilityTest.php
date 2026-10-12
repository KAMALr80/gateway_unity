<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 | Requests exactly as the released Flutter app (ketul-bbtl/unitygateway, 5.0.0+3008) sends them.
 | Installed copies can't be updated from here, so a change that fails one of these breaks the app in
 | people's hands: keep the API backwards compatible instead of changing these tests. The last test keeps the
 | newer endpoints usable by the app as well, not only by the website.
 */

function appCompatResetAuth(): void
{
    app('auth')->forgetGuards();
    app('tymon.jwt.auth')->unsetToken();
    app('tymon.jwt')->unsetToken();
}

/** The body built by checkout_screen.dart: one address used for billing and shipping, plus ids the API ignores. */
function appCompatCheckoutBody(User $user, array $items): array
{
    $party = [
        'first_name' => 'Jane',
        'last_name'  => 'Doe',
        'address_1'  => '1 Main St',
        'address_2'  => 'Suite 4',
        'city'       => 'Houston',
        'state'      => 'TX',
        'postcode'   => '77001',
        'country'    => 'US',
    ];

    $body = ['address_id' => 12, 'customer_id' => $user->id, 'items' => $items];
    foreach ($party as $k => $v) {
        $body["billing_{$k}"] = $v;
    }
    $body['billing_email'] = $user->email;
    $body['billing_phone'] = '7135550100';
    foreach ($party as $k => $v) {
        $body["shipping_{$k}"] = $v;
    }

    return $body;
}

test('the app signs in, refreshes its token and reads only access_token', function () {
    $user = User::factory()->create(['approval_status' => 'approved']);

    $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->json('access_token');
    expect($token)->toBeString();

    appCompatResetAuth();
    $refreshed = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'])
        ->postJson('/api/auth/refresh')
        ->assertOk()
        ->json('access_token');
    expect($refreshed)->toBeString()->not->toBe($token);
});

test('the app\'s catalogue queries still work', function () {
    $user  = User::factory()->create(['approval_status' => 'approved']);
    $group = Product::create(['name' => 'Cable', 'type' => 'grouped', 'regular_price' => '5.00', 'is_active' => true, 'in_stock' => true]);
    Product::create(['name' => 'Cable - Red', 'sku' => 'CABLE-RED', 'type' => 'variable', 'parent_id' => $group->id, 'regular_price' => '5.00', 'is_active' => true, 'in_stock' => true]);

    // Dio sends booleans as "true"/"false"; search/home/detail screens ask for 6, 10 and 20 per page.
    foreach ([20, 10, 6] as $perPage) {
        $this->actingAs($user, 'api')
            ->getJson("/api/products?in_stock=true&search=Cable&include_children=false&page=1&per_page={$perPage}")
            ->assertOk()
            ->assertJsonPath('meta.per_page', $perPage);
    }

    $this->actingAs($user, 'api')
        ->getJson("/api/products/{$group->id}?with_siblings=1")
        ->assertOk()
        ->assertJsonCount(1, 'children');
});

test('the app places an order with large quantities and many lines', function () {
    Queue::fake();
    $user  = User::factory()->create(['approval_status' => 'approved']);
    $group = Product::create(['name' => 'Cable', 'type' => 'grouped', 'regular_price' => '5.00', 'is_active' => true, 'in_stock' => true]);
    $red   = Product::create(['name' => 'Cable - Red', 'sku' => 'CABLE-RED', 'type' => 'variable', 'parent_id' => $group->id, 'regular_price' => '5.00', 'is_active' => true, 'in_stock' => true]);

    // The app limits a line only to known stock, so untracked stock allows any quantity.
    $items = [['product_id' => $red->id, 'quantity' => 1500]];
    foreach (range(1, 249) as $i) {
        $items[] = ['product_id' => Product::create(['name' => "Item {$i}", 'sku' => "ITEM-{$i}", 'regular_price' => '1.00', 'is_active' => true, 'in_stock' => true])->id, 'quantity' => 1];
    }

    $this->actingAs($user, 'api')
        ->postJson('/api/orders', appCompatCheckoutBody($user, $items))
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id']]);

    $order = Order::firstOrFail();
    expect($order->items()->count())->toBe(250)
        ->and((float) $order->line_total)->toBe(7749.0);
});

// The newer API (built with the website) must stay open to the app too, with the app's own login token.
test('the app\'s login token can use the server cart, duplicate-safe orders and image variants', function () {
    Queue::fake();
    $user    = User::factory()->create(['approval_status' => 'approved']);
    $product = Product::create(['name' => 'Straw', 'sku' => 'STRAW-1', 'regular_price' => '2.00', 'is_active' => true, 'in_stock' => true]);

    $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->json('access_token');
    appCompatResetAuth();
    $auth = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($auth)->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 3])
        ->assertOk()
        ->assertJsonPath('data.items.0.quantity', 3);

    $body  = appCompatCheckoutBody($user, [['product_id' => $product->id, 'quantity' => 3]]);
    $first = $this->withHeaders($auth + ['Idempotency-Key' => 'app-checkout-0001'])->postJson('/api/orders', $body)->assertCreated()->json('data.id');
    $again = $this->withHeaders($auth + ['Idempotency-Key' => 'app-checkout-0001'])->postJson('/api/orders', $body)->assertCreated()->json('data.id');
    expect($again)->toBe($first)->and(Order::count())->toBe(1);

    $this->withHeaders($auth)->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.items');
    $this->withHeaders($auth)->getJson('/api/products')->assertOk()->assertJsonStructure(['data' => [['image_variants']]]);
});
