<?php

use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function cartUser(string $status = 'approved'): User
{
    return User::factory()->create(['approval_status' => $status]);
}

function cartProduct(array $overrides = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'name'           => "Cart Product {$n}",
        'sku'            => "CART-{$n}",
        'type'           => 'simple',
        'regular_price'  => '10.00',
        'manage_stock'   => true,
        'stock_quantity' => 50,
        'in_stock'       => true,
        'is_active'      => true,
    ], $overrides));
}

function cartLine($response, int $productId): ?array
{
    return collect($response->json('data.items'))->firstWhere('product_id', $productId);
}

// ── Auth / isolation ─────────────────────────────────────────────────────────

test('cart endpoints require authentication', function () {
    $this->getJson('/api/cart')->assertUnauthorized();
    $this->postJson('/api/cart/items', ['product_id' => 1, 'quantity' => 1])->assertUnauthorized();
    $this->postJson('/api/cart/merge', ['items' => []])->assertUnauthorized();
});

test('scenario 1: cart added on one device is returned on another', function () {
    $user = cartUser();
    [$a, $b] = [cartProduct(), cartProduct()];

    // Device A
    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $a->id, 'quantity' => 2])->assertOk();
    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $b->id, 'quantity' => 1])->assertOk();

    // Device B (fresh request, same account)
    $res = $this->actingAs($user, 'api')->getJson('/api/cart')->assertOk();
    expect(cartLine($res, $a->id)['quantity'])->toBe(2)
        ->and(cartLine($res, $b->id)['quantity'])->toBe(1)
        ->and($res->json('data.item_count'))->toBe(3)
        ->and((float) $res->json('data.subtotal'))->toBe(30.0);
});

test('scenario 4: a different account never sees another users cart', function () {
    [$first, $second] = [cartUser(), cartUser()];
    $product = cartProduct();

    $this->actingAs($first, 'api')->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 3])->assertOk();

    $this->actingAs($second, 'api')->getJson('/api/cart')->assertOk()->assertJsonPath('data.items', []);
    // and cannot touch it by product id
    $this->actingAs($second, 'api')->deleteJson("/api/cart/items/{$product->id}")->assertOk();
    expect(CartItem::where('user_id', $first->id)->value('quantity'))->toBe(3);
});

test('a user id in the payload is ignored', function () {
    [$attacker, $victim] = [cartUser(), cartUser()];
    $product = cartProduct();

    $this->actingAs($attacker, 'api')
        ->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 1, 'user_id' => $victim->id])
        ->assertOk();

    expect(CartItem::where('user_id', $victim->id)->count())->toBe(0)
        ->and(CartItem::where('user_id', $attacker->id)->count())->toBe(1);
});

test('scenario 5: cart persists across logout and is restored on the next login', function () {
    $user    = cartUser();
    $product = cartProduct();

    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 4])->assertOk();
    auth('api')->logout();
    $this->app['auth']->forgetGuards();

    $this->actingAs($user, 'api')->getJson('/api/cart')->assertOk();
    expect(cartLine($this->actingAs($user, 'api')->getJson('/api/cart'), $product->id)['quantity'])->toBe(4);
});

// ── Updates from another device ──────────────────────────────────────────────

test('scenario 6: quantity change and removal on one device is what the other device loads', function () {
    $user = cartUser();
    [$a, $b] = [cartProduct(), cartProduct()];
    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $a->id, 'quantity' => 1]);
    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $b->id, 'quantity' => 1]);

    // Device B
    $this->actingAs($user, 'api')->patchJson("/api/cart/items/{$a->id}", ['quantity' => 7])->assertOk();
    $this->actingAs($user, 'api')->deleteJson("/api/cart/items/{$b->id}")->assertOk();

    // Device A resyncs
    $res = $this->actingAs($user, 'api')->getJson('/api/cart');
    expect(cartLine($res, $a->id)['quantity'])->toBe(7)
        ->and(cartLine($res, $b->id))->toBeNull();
});

test('setting quantity to zero removes the line', function () {
    $user = cartUser();
    $product = cartProduct();
    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2]);

    $this->actingAs($user, 'api')->patchJson("/api/cart/items/{$product->id}", ['quantity' => 0])
        ->assertOk()->assertJsonPath('data.items', []);
});

test('clearing the cart removes every line for that user only', function () {
    [$user, $other] = [cartUser(), cartUser()];
    $product = cartProduct();
    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2]);
    $this->actingAs($other, 'api')->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2]);

    $this->actingAs($user, 'api')->deleteJson('/api/cart')->assertOk()->assertJsonPath('data.items', []);
    expect(CartItem::where('user_id', $other->id)->count())->toBe(1);
});

// ── Duplicates / variants ────────────────────────────────────────────────────

test('scenario 7: adding the same product repeatedly increments one line, never duplicates it', function () {
    $user    = cartUser();
    $product = cartProduct();

    foreach (range(1, 5) as $_) {
        $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertOk();
    }

    expect(CartItem::where('user_id', $user->id)->count())->toBe(1)
        ->and(CartItem::where('user_id', $user->id)->value('quantity'))->toBe(5);
});

test('scenario 8: different variants of the same product stay separate lines', function () {
    $user   = cartUser();
    $parent = cartProduct(['type' => 'grouped', 'name' => 'Hoodie']);
    $red    = cartProduct(['parent_id' => $parent->id, 'name' => 'Hoodie - Red']);
    $blue   = cartProduct(['parent_id' => $parent->id, 'name' => 'Hoodie - Blue']);

    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $red->id, 'quantity' => 1]);
    $res = $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $blue->id, 'quantity' => 2]);

    expect($res->json('data.items'))->toHaveCount(2)
        ->and(cartLine($res, $red->id)['parent_id'])->toBe($parent->id)
        ->and(cartLine($res, $blue->id)['parent_name'])->toBe('Hoodie')
        ->and(cartLine($res, $blue->id)['quantity'])->toBe(2);
});

test('a grouped parent cannot be added without choosing a variant', function () {
    $parent = cartProduct(['type' => 'grouped']);

    $this->actingAs(cartUser(), 'api')->postJson('/api/cart/items', ['product_id' => $parent->id, 'quantity' => 1])
        ->assertStatus(422);
});

// ── Guest merge (scenario 3) ─────────────────────────────────────────────────

test('scenario 3: guest cart merges into an existing account cart', function () {
    $user = cartUser();
    [$shared, $guestOnly, $accountOnly] = [cartProduct(), cartProduct(), cartProduct()];
    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $shared->id, 'quantity' => 2]);
    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $accountOnly->id, 'quantity' => 1]);

    $res = $this->actingAs($user, 'api')->postJson('/api/cart/merge', [
        'merge_id' => 'device-b-1',
        'items'    => [
            ['product_id' => $shared->id, 'quantity' => 3],
            ['product_id' => $guestOnly->id, 'quantity' => 1],
        ],
    ])->assertOk();

    expect(cartLine($res, $shared->id)['quantity'])->toBe(5)
        ->and(cartLine($res, $guestOnly->id)['quantity'])->toBe(1)
        ->and(cartLine($res, $accountOnly->id)['quantity'])->toBe(1)
        ->and(CartItem::where('user_id', $user->id)->count())->toBe(3);
});

test('a retried merge with the same merge id is applied only once', function () {
    $user    = cartUser();
    $product = cartProduct();
    $payload = ['merge_id' => 'retry-1', 'items' => [['product_id' => $product->id, 'quantity' => 2]]];

    $this->actingAs($user, 'api')->postJson('/api/cart/merge', $payload)->assertOk();
    $this->actingAs($user, 'api')->postJson('/api/cart/merge', $payload)->assertOk();

    expect(CartItem::where('user_id', $user->id)->value('quantity'))->toBe(2);
});

test('merge skips invalid, disabled and out of stock products and caps quantities to stock', function () {
    $user     = cartUser();
    $limited  = cartProduct(['stock_quantity' => 3]);
    $disabled = cartProduct(['is_active' => false]);
    $soldOut  = cartProduct(['in_stock' => false]);

    $res = $this->actingAs($user, 'api')->postJson('/api/cart/merge', ['items' => [
        ['product_id' => $limited->id, 'quantity' => 10],
        ['product_id' => $disabled->id, 'quantity' => 1],
        ['product_id' => $soldOut->id, 'quantity' => 1],
        ['product_id' => 999999, 'quantity' => 1],
    ]])->assertOk();

    expect($res->json('data.items'))->toHaveCount(1)
        ->and(cartLine($res, $limited->id)['quantity'])->toBe(3)
        ->and($res->json('notices'))->not->toBeEmpty();
});

// ── Validation against the live catalog ──────────────────────────────────────

test('quantity is capped to available stock when adding', function () {
    $user    = cartUser();
    $product = cartProduct(['stock_quantity' => 4]);

    $res = $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 10])->assertOk();

    expect(cartLine($res, $product->id)['quantity'])->toBe(4)
        ->and($res->json('notices'))->toHaveCount(1);
});

test('unknown, disabled and out of stock products are rejected', function () {
    $user = cartUser();

    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => 999999, 'quantity' => 1])->assertStatus(422);
    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => cartProduct(['is_active' => false])->id, 'quantity' => 1])->assertStatus(422);
    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => cartProduct(['in_stock' => false])->id, 'quantity' => 1])->assertStatus(422);
    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => cartProduct()->id, 'quantity' => 0])->assertStatus(422);
    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => cartProduct()->id, 'quantity' => 5000])->assertStatus(422);
});

test('loading the cart drops products that were disabled or deleted and caps lowered stock', function () {
    $user = cartUser();
    [$disabled, $deleted, $lowered, $fine] = [cartProduct(), cartProduct(), cartProduct(), cartProduct()];
    foreach ([$disabled, $deleted, $lowered, $fine] as $p) {
        $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $p->id, 'quantity' => 5]);
    }

    $disabled->update(['is_active' => false]);
    $deleted->delete();
    $lowered->update(['stock_quantity' => 2]);

    $res = $this->actingAs($user, 'api')->getJson('/api/cart')->assertOk();

    expect(collect($res->json('data.items'))->pluck('product_id')->all())->toEqualCanonicalizing([$lowered->id, $fine->id])
        ->and(cartLine($res, $lowered->id)['quantity'])->toBe(2)
        ->and(CartItem::where('user_id', $user->id)->count())->toBe(2);
});

test('an item that went out of stock stays visible but flagged, and can be reduced but not increased', function () {
    $user    = cartUser();
    $product = cartProduct();
    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 3]);
    $product->update(['in_stock' => false]);

    $res = $this->actingAs($user, 'api')->getJson('/api/cart');
    expect(cartLine($res, $product->id)['in_stock'])->toBeFalse()
        ->and((float) $res->json('data.subtotal'))->toBe(0.0);

    $this->actingAs($user, 'api')->patchJson("/api/cart/items/{$product->id}", ['quantity' => 1])->assertOk();
    $this->actingAs($user, 'api')->patchJson("/api/cart/items/{$product->id}", ['quantity' => 5])->assertStatus(422);
});

test('prices always come from the catalog and follow price changes', function () {
    $user    = cartUser();
    $product = cartProduct(['regular_price' => '10.00']);

    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2, 'price' => 0.01]);
    $product->update(['sale_price' => '7.50']);

    $res = $this->actingAs($user, 'api')->getJson('/api/cart');
    expect(cartLine($res, $product->id)['price'])->toBe(7.5)
        ->and((float) $res->json('data.subtotal'))->toBe(15.0);
});

test('prices are hidden from users who are not approved', function () {
    $product = cartProduct();
    $res = $this->actingAs(cartUser('pending'), 'api')->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 1]);

    expect(cartLine($res, $product->id)['price'])->toBeNull()
        ->and($res->json('data.subtotal'))->toBeNull()
        ->and($res->json('data.prices_visible'))->toBeFalse();
});

test('placing an order removes the ordered products from the account cart', function () {
    Queue::fake(); // don't run the ERP sync job
    $user = cartUser();
    [$ordered, $kept] = [cartProduct(), cartProduct()];
    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $ordered->id, 'quantity' => 1]);
    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $kept->id, 'quantity' => 1]);

    $address = [
        'first_name' => 'Jane', 'last_name' => 'Doe', 'address_1' => '1 Main St', 'city' => 'Town',
        'postcode' => '12345', 'country' => 'US', 'phone' => '5550000',
    ];
    $payload = ['items' => [['product_id' => $ordered->id, 'quantity' => 1]], 'billing_email' => $user->email];
    foreach ($address as $k => $v) {
        $payload["billing_{$k}"]  = $v;
        $payload["shipping_{$k}"] = $v;
    }

    $this->actingAs($user, 'api')->postJson('/api/orders', $payload)->assertCreated();

    expect(CartItem::where('user_id', $user->id)->pluck('product_id')->all())->toBe([$kept->id]);
});
