<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * POST /api/orders with an Idempotency-Key header: the same key never creates a second order.
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $category = Category::create(['erp_category_id' => 1, 'name' => 'Wraps', 'is_active' => true]);
    $this->product = Product::create(['name' => 'Simple product', 'regular_price' => 12.5, 'is_active' => true, 'in_stock' => true, 'category_id' => $category->id]);
    $this->user = User::factory()->create(['approval_status' => 'approved']);
    $this->token = auth('api')->login($this->user);
});

function idempotentOrder(int $productId): array
{
    return [
        'billing_first_name' => 'Ada', 'billing_last_name' => 'Buyer', 'billing_address_1' => '1 Main St', 'billing_city' => 'Nashua', 'billing_state' => 'NH',
        'billing_postcode' => '03063', 'billing_country' => 'US', 'billing_email' => 'ada@example.test', 'billing_phone' => '5555555555',
        'shipping_first_name' => 'Ada', 'shipping_last_name' => 'Buyer', 'shipping_address_1' => '1 Main St', 'shipping_city' => 'Nashua',
        'shipping_postcode' => '03063', 'shipping_country' => 'US',
        'items' => [['product_id' => $productId, 'quantity' => 2]],
    ];
}

it('returns the same order when the same key is sent again', function () {
    $headers = ['Idempotency-Key' => 'checkout-key-0001'];
    $first = $this->withToken($this->token)->withHeaders($headers)->postJson('/api/orders', idempotentOrder($this->product->id))->assertCreated();
    $again = $this->withToken($this->token)->withHeaders($headers)->postJson('/api/orders', idempotentOrder($this->product->id))->assertCreated();

    expect($again->json('data.id'))->toBe($first->json('data.id'))
        ->and(Order::count())->toBe(1);
});

it('places separate orders for different keys and without a key', function () {
    $this->withToken($this->token)->withHeaders(['Idempotency-Key' => 'checkout-key-aaaa'])->postJson('/api/orders', idempotentOrder($this->product->id))->assertCreated();
    $this->withToken($this->token)->withHeaders(['Idempotency-Key' => 'checkout-key-bbbb'])->postJson('/api/orders', idempotentOrder($this->product->id))->assertCreated();
    $this->flushHeaders()->withToken($this->token)->postJson('/api/orders', idempotentOrder($this->product->id))->assertCreated();

    expect(Order::count())->toBe(3);
});

it('never returns another account\'s order for a reused key', function () {
    $headers = ['Idempotency-Key' => 'shared-key-12345'];
    $this->withToken($this->token)->withHeaders($headers)->postJson('/api/orders', idempotentOrder($this->product->id))->assertCreated();

    $other = User::factory()->create(['approval_status' => 'approved']);
    app('auth')->forgetGuards();
    $response = $this->withToken(auth('api')->login($other))->withHeaders($headers)->postJson('/api/orders', idempotentOrder($this->product->id))->assertCreated();

    expect(Order::count())->toBe(2)
        ->and(Order::find($response->json('data.id'))->user_id)->toBe($other->id);
});

it('does not remember a failed attempt, so a corrected retry with the same key works', function () {
    $headers = ['Idempotency-Key' => 'checkout-key-retry'];
    $this->product->update(['in_stock' => false]);
    $this->withToken($this->token)->withHeaders($headers)->postJson('/api/orders', idempotentOrder($this->product->id))->assertStatus(422);

    $this->product->update(['in_stock' => true]);
    $this->withToken($this->token)->withHeaders($headers)->postJson('/api/orders', idempotentOrder($this->product->id))->assertCreated();
    expect(Order::count())->toBe(1);
});

it('answers 409 while the same key is still being placed', function () {
    $lock = Cache::lock('order-idempotency:'.$this->user->id.':'.hash('sha256', 'checkout-key-busy').':lock', 30);
    $lock->get();

    $this->withToken($this->token)->withHeaders(['Idempotency-Key' => 'checkout-key-busy'])->postJson('/api/orders', idempotentOrder($this->product->id))->assertStatus(409);
    expect(Order::count())->toBe(0);
    $lock->release();
});

it('rejects a malformed key', function () {
    $this->withToken($this->token)->withHeaders(['Idempotency-Key' => 'bad key!'])->postJson('/api/orders', idempotentOrder($this->product->id))->assertStatus(422);
    expect(Order::count())->toBe(0);
});
