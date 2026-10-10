<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** meta.from / meta.to: 1-based positions of the first and last product on the page, null when the page is empty. */
beforeEach(function () {
    $this->cat = Category::create(['erp_category_id' => 1, 'name' => 'Papers', 'is_active' => true]);
    foreach (range(1, 45) as $i) {
        Product::create(['name' => sprintf('Paper %02d', $i), 'regular_price' => 1, 'is_active' => true, 'in_stock' => true,
            'category_id' => $i <= 12 ? $this->cat->id : null]);
    }
});

function rangeMeta($test, string $query): array
{
    return $test->getJson('/api/products?' . $query)->assertOk()->json('meta');
}

test('first, middle and partial last pages', function () {
    expect(rangeMeta($this, 'per_page=20'))->toMatchArray(['current_page' => 1, 'from' => 1, 'to' => 20, 'total' => 45, 'last_page' => 3, 'per_page' => 20])
        ->and(rangeMeta($this, 'per_page=20&page=2'))->toMatchArray(['from' => 21, 'to' => 40])
        ->and(rangeMeta($this, 'per_page=20&page=3'))->toMatchArray(['from' => 41, 'to' => 45]);
});

test('empty results and out-of-range pages have no range', function () {
    expect(rangeMeta($this, 'search=nothing-matches'))->toMatchArray(['total' => 0, 'from' => null, 'to' => null])
        ->and(rangeMeta($this, 'per_page=20&page=9'))->toMatchArray(['total' => 45, 'from' => null, 'to' => null]);
});

test('search and category filters count within the filtered set', function () {
    expect(rangeMeta($this, 'search=Paper 1&per_page=5'))->toMatchArray(['total' => 10, 'from' => 1, 'to' => 5])
        ->and(rangeMeta($this, "category_id={$this->cat->id}&per_page=5&page=3"))->toMatchArray(['total' => 12, 'from' => 11, 'to' => 12]);
});
