<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** The storefront's "Feature Product" page: an admin-managed flag the ERP sync leaves alone. */
beforeEach(function () {
    $this->featured = Product::create(['name' => 'Featured pipe', 'regular_price' => 5, 'is_active' => true, 'in_stock' => true, 'erp_product_id' => 'P-1']);
    $this->featured->forceFill(['is_featured' => true])->save();
    $this->plain = Product::create(['name' => 'Plain paper', 'regular_price' => 1, 'is_active' => true, 'in_stock' => true, 'erp_product_id' => 'P-2']);
    $this->admin = User::factory()->create(['is_admin' => true]);
});

it('lists only featured products with ?featured=1 and everything without it', function () {
    $this->getJson('/api/products?featured=1')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Featured pipe');

    expect(count($this->getJson('/api/products')->assertOk()->json('data')))->toBe(2);
    $this->getJson('/api/products?featured=maybe')->assertUnprocessable();
});

it('lets an admin add and remove a featured product, and the storefront list follows at once', function () {
    $this->getJson('/api/products?featured=1')->assertJsonCount(1, 'data'); // warm the list cache

    $this->actingAs($this->admin)->patch("/admin/products/{$this->plain->id}/featured", ['is_featured' => true])->assertRedirect();
    expect($this->plain->fresh()->is_featured)->toBeTrue();
    $this->getJson('/api/products?featured=1')->assertJsonCount(2, 'data');

    $this->actingAs($this->admin)->patch("/admin/products/{$this->featured->id}/featured", ['is_featured' => false])->assertRedirect();
    $this->getJson('/api/products?featured=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Plain paper');
});

it('only lets administrators change the featured flag', function () {
    $customer = User::factory()->create(['is_admin' => false]);

    $this->actingAs($customer)->patch("/admin/products/{$this->plain->id}/featured", ['is_featured' => true])->assertForbidden();
    $this->actingAs($this->admin)->patch("/admin/products/{$this->plain->id}/featured", [])->assertSessionHasErrors('is_featured');
    expect($this->plain->fresh()->is_featured)->toBeFalse();
});

it('keeps the featured flag when the ERP syncs the product again', function () {
    config(['services.erp.internal_key' => 'test-key']);

    $this->withHeader('X-Internal-Key', 'test-key')->postJson('/internal/products/sync', ['products' => [
        ['erp_product_id' => 'P-1', 'name' => 'Featured pipe (renamed)', 'regular_price' => 6],
    ]])->assertOk();

    expect($this->featured->fresh())
        ->name->toBe('Featured pipe (renamed)')
        ->is_featured->toBeTrue();
});

it('shows the parent product when one of its variants is featured', function () {
    $parent = Product::create(['name' => 'Soda case', 'type' => 'grouped', 'regular_price' => 10, 'is_active' => true, 'in_stock' => true, 'erp_product_id' => 'P-3']);
    $variant = Product::create(['name' => 'Soda case - Kiwi', 'parent_id' => $parent->id, 'regular_price' => 10, 'is_active' => true, 'in_stock' => true, 'erp_product_id' => 'P-4']);
    $variant->forceFill(['is_featured' => true])->save();

    $names = collect($this->getJson('/api/products?featured=1')->assertOk()->json('data'))->pluck('name')->all();

    expect($names)->toContain('Soda case', 'Featured pipe')->not->toContain('Soda case - Kiwi', 'Plain paper');
});
