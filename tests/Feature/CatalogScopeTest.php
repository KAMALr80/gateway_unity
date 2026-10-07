<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\WishlistItem;
use App\Support\CatalogScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    CatalogScope::flush();

    $this->bags     = Category::create(['erp_category_id' => 333, 'name' => 'BAGS', 'is_active' => true]);
    $this->bagSub   = Category::create(['erp_category_id' => 334, 'name' => 'PAPER BAGS', 'parent_id' => $this->bags->id, 'is_active' => true]);
    $this->tobacco  = Category::create(['erp_category_id' => 100, 'name' => 'PIPE TOBACCO', 'is_active' => true]);
    $bagBrand       = Brand::create(['erp_brand_id' => 1, 'name' => 'BagCo', 'is_active' => true]);
    $tobaccoBrand   = Brand::create(['erp_brand_id' => 2, 'name' => 'Largo', 'is_active' => true]);

    $make = fn (string $name, array $attributes) => Product::create(array_merge(
        ['name' => $name, 'regular_price' => 5, 'is_active' => true, 'in_stock' => true], $attributes
    ));
    $this->bag       = $make('SHOPPING BAG LARGE', ['category_id' => $this->bags->id, 'brand_id' => $bagBrand->id]);
    $this->paperBag  = $make('PAPER BAG SMALL', ['category_id' => $this->bags->id, 'sub_category_id' => $this->bagSub->id]);
    $this->pipeBag   = $make('LARGO PIPE TOBACCO BAG 16OZ', ['category_id' => $this->tobacco->id, 'brand_id' => $tobaccoBrand->id]);

    $this->reviewer = User::factory()->create(['approval_status' => 'approved']);
    $this->reviewer->forceFill(['catalog_category_id' => $this->bags->id])->save();
    $this->customer = User::factory()->create(['approval_status' => 'approved']);

    $this->tokenFor = fn (User $user) => auth('api')->login($user);
});

/** A request as a fresh server process sees it: no user left over from the last one. */
function fresh($test, ?string $token = null)
{
    app('auth')->forgetGuards();
    // The guard reads the token through this shared instance, which keeps
    // the last one issued; a real request starts without it.
    app('tymon.jwt')->unsetToken();

    return $token ? $test->withToken($token) : $test->withHeaders(['Authorization' => '']);
}

it('shows a scoped user only their category, without leaking through the shared cache either way', function () {
    $customer = ($this->tokenFor)($this->customer);
    $reviewer = ($this->tokenFor)($this->reviewer);
    $names    = fn ($response) => collect($response->json('data'))->pluck('name')->sort()->values()->all();

    // The normal customer fills the shared cache first...
    expect($names(fresh($this, $customer)->getJson('/api/products')))->toHaveCount(3);
    // ...the reviewer still sees only BAGS (and its sub-category)...
    expect($names(fresh($this, $reviewer)->getJson('/api/products')))->toBe(['PAPER BAG SMALL', 'SHOPPING BAG LARGE']);
    // ...and the customer still sees everything afterwards.
    expect($names(fresh($this, $customer)->getJson('/api/products')))->toHaveCount(3);

    expect($names(fresh($this, $reviewer)->getJson('/api/products?search=BAG')))->toBe(['PAPER BAG SMALL', 'SHOPPING BAG LARGE']);
    expect($names(fresh($this, $reviewer)->getJson('/api/products?category_id=' . $this->tobacco->id)))->toBe([]);
});

it('hides product detail outside the category, but not from others', function () {
    fresh($this, ($this->tokenFor)($this->customer))->getJson("/api/products/{$this->pipeBag->id}")->assertOk();
    fresh($this, ($this->tokenFor)($this->reviewer))->getJson("/api/products/{$this->pipeBag->id}")->assertNotFound();
    fresh($this, ($this->tokenFor)($this->reviewer))->getJson("/api/products/{$this->paperBag->id}")->assertOk();
});

it('shows a scoped user only their category in the category tree', function () {
    fresh($this)->getJson('/api/categories')->assertJsonCount(2, 'data');

    fresh($this, ($this->tokenFor)($this->reviewer))->getJson('/api/categories')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'BAGS')
        ->assertJsonPath('data.0.children.0.name', 'PAPER BAGS');

    fresh($this, ($this->tokenFor)($this->reviewer))->getJson("/api/categories/{$this->tobacco->id}")->assertNotFound();
    fresh($this)->getJson('/api/categories')->assertJsonCount(2, 'data');
});

it('shows a scoped user only brands they have products of', function () {
    fresh($this)->getJson('/api/brands')->assertJsonCount(2, 'data');
    fresh($this, ($this->tokenFor)($this->reviewer))->getJson('/api/brands')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'BagCo');
});

it('keeps the wishlist and checkout inside the category', function () {
    $token = ($this->tokenFor)($this->reviewer);
    WishlistItem::create(['user_id' => $this->reviewer->id, 'product_id' => $this->pipeBag->id]);

    fresh($this, $token)->postJson('/api/wishlist', ['product_id' => $this->pipeBag->id])->assertNotFound();
    fresh($this, $token)->postJson('/api/wishlist', ['product_id' => $this->bag->id])->assertCreated();
    fresh($this, $token)->getJson('/api/wishlist')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product.name', 'SHOPPING BAG LARGE');

    Queue::fake();
    $address = ['first_name' => 'App', 'last_name' => 'Review', 'address_1' => '1 Main St', 'city' => 'Centerville', 'postcode' => '31028', 'country' => 'US'];
    $order = fn (Product $product) => array_merge(
        ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'billing_email' => 'review@example.com', 'billing_phone' => '4785550100'],
        collect($address)->mapWithKeys(fn ($v, $k) => ["billing_{$k}" => $v, "shipping_{$k}" => $v])->all()
    );

    fresh($this, $token)->postJson('/api/orders', $order($this->pipeBag))->assertStatus(422);
    fresh($this, $token)->postJson('/api/orders', $order($this->bag))->assertCreated();
});
