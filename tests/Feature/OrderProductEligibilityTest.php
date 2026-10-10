<?php

use App\Jobs\SendOrderToErp;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * POST /api/orders takes the same products the cart takes: active, in stock, inside the account's catalog,
 * and never a grouped product (the variant picker of a variation group, which has no price of its own).
 */
beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();

    $this->category = Category::create(['erp_category_id' => 1, 'name' => 'Wraps', 'is_active' => true]);
    $this->other    = Category::create(['erp_category_id' => 2, 'name' => 'Glass', 'is_active' => true]);
    $make = fn (string $name, array $attributes = []) => Product::create(array_merge(
        ['name' => $name, 'regular_price' => 10, 'is_active' => true, 'in_stock' => true, 'category_id' => $this->category->id], $attributes
    ));
    $this->simple    = $make('Simple product', ['regular_price' => 12.5]);
    $this->group     = $make('Wraps group', ['type' => 'grouped', 'regular_price' => 0]);
    $this->variation = $make('Wraps - Mango', ['type' => 'variable', 'parent_id' => $this->group->id, 'regular_price' => 7]);
    $this->inactive  = $make('Discontinued', ['is_active' => false]);
    $this->soldOut   = $make('Sold out', ['in_stock' => false]);
    $this->elsewhere = $make('Other category', ['category_id' => $this->other->id]);

    $this->user = User::factory()->create(['approval_status' => 'approved']);
});

function eligibilityOrder(array $items, array $extra = []): array
{
    return array_merge([
        'billing_first_name' => 'Ada', 'billing_last_name' => 'Buyer', 'billing_address_1' => '1 Main St', 'billing_city' => 'Nashua',
        'billing_postcode' => '03063', 'billing_country' => 'US', 'billing_email' => 'ada@example.test', 'billing_phone' => '5555555555',
        'shipping_first_name' => 'Ada', 'shipping_last_name' => 'Buyer', 'shipping_address_1' => '1 Main St', 'shipping_city' => 'Nashua',
        'shipping_postcode' => '03063', 'shipping_country' => 'US',
        'items' => $items,
    ], $extra);
}

function placeEligibilityOrder($test, User $user, array $items, array $extra = [])
{
    return $test->actingAs($user, 'api')->postJson('/api/orders', eligibilityOrder($items, $extra));
}

it('accepts a simple product at its catalog price', function () {
    placeEligibilityOrder($this, $this->user, [['product_id' => $this->simple->id, 'quantity' => 2]])
        ->assertCreated()
        ->assertJsonPath('data.total', '25.00');

    Queue::assertPushed(SendOrderToErp::class, 1);
});

it('accepts a variation of a group', function () {
    placeEligibilityOrder($this, $this->user, [['product_id' => $this->variation->id, 'quantity' => 3]])
        ->assertCreated()
        ->assertJsonPath('data.total', '21.00');
});

it('rejects a grouped product without creating an order or contacting the ERP', function () {
    placeEligibilityOrder($this, $this->user, [['product_id' => $this->group->id, 'quantity' => 1]])
        ->assertUnprocessable();

    expect(Order::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects the whole order when a grouped product is mixed with valid ones', function () {
    placeEligibilityOrder($this, $this->user, [
        ['product_id' => $this->simple->id, 'quantity' => 1],
        ['product_id' => $this->group->id, 'quantity' => 1],
    ])->assertUnprocessable();

    expect(Order::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects inactive, sold out and unknown products', function () {
    foreach ([$this->inactive->id, $this->soldOut->id] as $id) {
        placeEligibilityOrder($this, $this->user, [['product_id' => $id, 'quantity' => 1]])->assertUnprocessable();
    }
    placeEligibilityOrder($this, $this->user, [['product_id' => 999999, 'quantity' => 1]])->assertUnprocessable();

    expect(Order::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('keeps a catalog-limited account inside its catalog', function () {
    $limited = User::factory()->create(['approval_status' => 'approved']);
    $limited->forceFill(['catalog_category_id' => $this->category->id])->save();

    placeEligibilityOrder($this, $limited, [['product_id' => $this->elsewhere->id, 'quantity' => 1]])->assertUnprocessable();
    expect(Order::count())->toBe(0);

    placeEligibilityOrder($this, $limited, [['product_id' => $this->simple->id, 'quantity' => 1]])->assertCreated();
});

it('ignores prices and totals sent by the client', function () {
    $response = placeEligibilityOrder($this, $this->user, [['product_id' => $this->simple->id, 'quantity' => 1, 'unit_price' => 0.01]], [
        'discount_total' => 12.5, 'shipping_total' => 0, 'total_tax' => 0, 'currency' => 'EUR', 'total' => 0.01, 'line_total' => 0.01,
    ])->assertCreated();

    $order = Order::with('items')->find($response->json('data.id'));
    expect((float) $order->total)->toBe(12.5)
        ->and((float) $order->discount_total)->toBe(0.0)
        ->and($order->currency)->toBe('USD')
        ->and((float) $order->items->first()->unit_price)->toBe(12.5);
});
