<?php

use App\Models\HomepageConfig;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeProduct(string $name, array $overrides = []): Product
{
    return Product::create(array_merge([
        'name' => $name,
        'slug' => str($name)->slug()->toString(),
        'type' => 'simple',
        'regular_price' => 10,
        'in_stock' => true,
        'is_active' => true,
    ], $overrides));
}

function carouselIds(string $source): array
{
    $config = HomepageConfig::firstOrCreate(['client_key' => 'unity'], ['label' => 'Unity', 'is_active' => true]);
    $config->sections()->create(['type' => 'product_carousel', 'title' => 'Carousel', 'sort_order' => 0, 'is_active' => true, 'settings' => ['source' => $source, 'limit' => 5]]);

    return test()->getJson('/api/homepage?client=unity')->assertOk()->json('sections.0.data.product_ids');
}

it('fills a carousel with the newest in-stock products', function () {
    $old = makeProduct('Old');
    $new = makeProduct('New');
    makeProduct('Sold out', ['in_stock' => false]);
    makeProduct('Hidden', ['is_active' => false]);

    expect(carouselIds('newest'))->toBe([$new->id, $old->id]);
});

it('fills a carousel with products that are really on sale', function () {
    makeProduct('Full price');
    $sale = makeProduct('Discounted', ['sale_price' => 7]);
    makeProduct('Fake sale', ['sale_price' => 10]);

    expect(carouselIds('on_sale'))->toBe([$sale->id]);
});

it('keeps the best sellers carousel empty when there are no orders', function () {
    makeProduct('Never ordered');

    expect(carouselIds('best_selling'))->toBe([]);
});
