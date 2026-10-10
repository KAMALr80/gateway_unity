<?php

use App\Models\Product;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function wishlistProduct(array $overrides = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'name' => "Wish Product {$n}", 'sku' => "WISH-{$n}", 'regular_price' => '5.00', 'is_active' => true,
    ], $overrides));
}

test('scenario 2: wishlist added on one device is returned on another', function () {
    $user    = User::factory()->create(['approval_status' => 'approved']);
    $product = wishlistProduct();

    $this->actingAs($user, 'api')->postJson('/api/wishlist', ['product_id' => $product->id])->assertCreated();

    $this->actingAs($user, 'api')->getJson('/api/wishlist')
        ->assertOk()
        ->assertJsonPath('data.0.product.id', $product->id)
        ->assertJsonStructure(['data' => [['id', 'added_at', 'product' => ['id', 'name', 'sku', 'in_stock', 'stock_quantity', 'image']]]]);
});

test('adding the same product twice keeps a single wishlist row', function () {
    $user    = User::factory()->create();
    $product = wishlistProduct();

    $this->actingAs($user, 'api')->postJson('/api/wishlist', ['product_id' => $product->id])->assertCreated();
    $this->actingAs($user, 'api')->postJson('/api/wishlist', ['product_id' => $product->id])->assertOk();

    expect(WishlistItem::where('user_id', $user->id)->count())->toBe(1);
});

test('removing by product id only affects the current users wishlist', function () {
    [$user, $other] = [User::factory()->create(), User::factory()->create()];
    $product = wishlistProduct();
    $this->actingAs($user, 'api')->postJson('/api/wishlist', ['product_id' => $product->id]);
    $this->actingAs($other, 'api')->postJson('/api/wishlist', ['product_id' => $product->id]);

    $this->actingAs($user, 'api')->deleteJson("/api/wishlist/product/{$product->id}")->assertOk();

    expect(WishlistItem::where('user_id', $user->id)->count())->toBe(0)
        ->and(WishlistItem::where('user_id', $other->id)->count())->toBe(1);
});

test('a different account does not see another users wishlist', function () {
    [$user, $other] = [User::factory()->create(), User::factory()->create()];
    $this->actingAs($user, 'api')->postJson('/api/wishlist', ['product_id' => wishlistProduct()->id]);

    $this->actingAs($other, 'api')->getJson('/api/wishlist')->assertOk()->assertJsonPath('data', []);
});
