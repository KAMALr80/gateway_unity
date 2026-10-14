<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** The storefront's "Best Sellers" page: products ranked in the admin (1 = first). */
function rankedProduct(string $name, ?int $rank, array $extra = []): Product
{
    $product = Product::create(['name' => $name, 'regular_price' => 1, 'is_active' => true, 'in_stock' => true] + $extra);
    $product->forceFill(['best_seller_rank' => $rank])->save();

    return $product;
}

function bestSellerNames(object $test, string $query = 'best_seller=1&sort=best_selling'): array
{
    return collect($test->getJson("/api/products?{$query}")->assertOk()->json('data'))->pluck('name')->all();
}

beforeEach(function () {
    rankedProduct('Zig zag papers', 2);
    rankedProduct('Apple vape', 3);
    rankedProduct('Mango hookah', 1);
    rankedProduct('Not ranked', null);
    $this->admin = User::factory()->create(['is_admin' => true]);
});

it('lists only ranked products, in rank order', function () {
    expect(bestSellerNames($this))->toBe(['Mango hookah', 'Zig zag papers', 'Apple vape']);
});

it('puts ranked products first when sorting the whole catalogue by best selling', function () {
    expect(bestSellerNames($this, 'sort=best_selling'))->toBe(['Mango hookah', 'Zig zag papers', 'Apple vape', 'Not ranked']);
});

it('ranks the parent when a variant is ranked', function () {
    $parent = rankedProduct('Soda case', null, ['type' => 'grouped']);
    rankedProduct('Soda case - Kiwi', 1, ['parent_id' => $parent->id]);

    expect(bestSellerNames($this))->toContain('Soda case')->not->toContain('Soda case - Kiwi');
});

it('lets an admin add, re-rank and remove a best seller, and the list follows at once', function () {
    $plain = Product::where('name', 'Not ranked')->first();
    bestSellerNames($this); // warm the list cache

    $this->actingAs($this->admin)->patch("/admin/products/{$plain->id}/best-seller", ['best_seller' => true])->assertRedirect();
    expect($plain->fresh()->best_seller_rank)->toBe(4); // after the last one
    expect(bestSellerNames($this))->toBe(['Mango hookah', 'Zig zag papers', 'Apple vape', 'Not ranked']);

    $this->actingAs($this->admin)->patch("/admin/products/{$plain->id}/best-seller", ['best_seller' => true, 'rank' => 1])->assertRedirect();
    expect(bestSellerNames($this)[0])->toBe('Mango hookah'); // tie at rank 1: name decides
    expect($plain->fresh()->best_seller_rank)->toBe(1);

    $this->actingAs($this->admin)->patch("/admin/products/{$plain->id}/best-seller", ['best_seller' => false])->assertRedirect();
    expect($plain->fresh()->best_seller_rank)->toBeNull()
        ->and(bestSellerNames($this))->not->toContain('Not ranked');
});

it('only lets administrators rank products, with a valid rank', function () {
    $plain = Product::where('name', 'Not ranked')->first();

    $this->actingAs(User::factory()->create(['is_admin' => false]))
        ->patch("/admin/products/{$plain->id}/best-seller", ['best_seller' => true])->assertForbidden();
    $this->actingAs($this->admin)
        ->patch("/admin/products/{$plain->id}/best-seller", ['best_seller' => true, 'rank' => 0])->assertSessionHasErrors('rank');

    expect($plain->fresh()->best_seller_rank)->toBeNull();
});
