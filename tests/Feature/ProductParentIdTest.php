<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\CatalogScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Product responses carry parent_id (null for simple and grouped products) so a client can group a
 * variation under its product without asking for siblings.
 */
beforeEach(function () {
    CatalogScope::flush();
    $this->cat = Category::create(['erp_category_id' => 1, 'name' => 'Wraps', 'is_active' => true]);
    $make = fn (string $name, array $a = []) => Product::create(array_merge(['name' => $name, 'regular_price' => 3, 'is_active' => true, 'in_stock' => true, 'category_id' => $this->cat->id], $a));
    $this->simple    = $make('Lighter');
    $this->group     = $make('Wraps', ['type' => 'grouped']);
    $this->variation = $make('Wraps - Mango', ['type' => 'variable', 'parent_id' => $this->group->id]);
    $this->orphan    = $make('Wraps - Grape', ['type' => 'variable', 'parent_id' => $this->group->id]);
});

it('returns parent_id on product detail', function () {
    $this->getJson("/api/products/{$this->simple->id}")->assertOk()->assertJsonPath('parent_id', null);
    $this->getJson("/api/products/{$this->group->id}")->assertOk()->assertJsonPath('parent_id', null)
        ->assertJsonPath('children.0.parent_id', $this->group->id);
    $this->getJson("/api/products/{$this->variation->id}")->assertOk()->assertJsonPath('parent_id', $this->group->id)
        ->assertJsonPath('parent', null); // the parent object stays opt-in (with_siblings)
});

it('returns parent_id in product lists', function () {
    $rows = collect($this->getJson('/api/products?include_children=1&per_page=50')->assertOk()->json('data'))->keyBy('id');

    expect($rows[$this->simple->id]['parent_id'])->toBeNull()
        ->and($rows[$this->variation->id]['parent_id'])->toBe($this->group->id);
});

it('still returns the variation when its parent is inactive, with the parent id only', function () {
    $this->group->update(['is_active' => false]);

    $this->getJson("/api/products/{$this->orphan->id}")->assertOk()
        ->assertJsonPath('parent_id', $this->group->id)
        ->assertJsonMissingPath('parent.name');
});

it('does not reveal anything to a catalog-limited account outside its catalog', function () {
    $other = Category::create(['erp_category_id' => 2, 'name' => 'Glass', 'is_active' => true]);
    $user = User::factory()->create(['approval_status' => 'approved']);
    $user->forceFill(['catalog_category_id' => $other->id])->save();

    $this->actingAs($user, 'api')->getJson("/api/products/{$this->variation->id}")->assertNotFound();
});
