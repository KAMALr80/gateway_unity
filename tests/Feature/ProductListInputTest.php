<?php

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * GET /api/products answers bad per_page / search input with a 422, never an SQL error or a 500,
 * while valid paging and searching keep working as documented (default 20, maximum 100).
 */
beforeEach(function () {
    foreach (range(1, 25) as $i) {
        Product::create(['name' => sprintf('Rolling paper %02d', $i), 'regular_price' => 1, 'is_active' => true, 'in_stock' => true]);
    }
    Product::create(['name' => 'Glass pipe', 'regular_price' => 1, 'is_active' => true, 'in_stock' => true]);
});

test('per_page defaults to 20 and takes valid values', function () {
    $this->getJson('/api/products')->assertOk()->assertJsonPath('meta.per_page', 20)->assertJsonCount(20, 'data');
    $this->getJson('/api/products?per_page=5')->assertOk()->assertJsonPath('meta.per_page', 5)->assertJsonCount(5, 'data');
    $this->getJson('/api/products?per_page=5&page=2')->assertOk()->assertJsonPath('meta.current_page', 2);
});

test('an oversized per_page is capped, as documented', function () {
    $this->getJson('/api/products?per_page=5000')->assertOk()->assertJsonPath('meta.per_page', 100);
});

test('zero, negative and non-numeric per_page are a 422, not a 500', function () {
    foreach (['0', '-5', 'abc', '1.5', '1e3'] as $value) {
        $response = $this->getJson('/api/products?per_page=' . urlencode($value));
        $response->assertUnprocessable()->assertJsonValidationErrors('per_page');
        expect($response->getContent())->not->toContain('SQLSTATE');
    }
    $this->getJson('/api/products?per_page[]=5')->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

test('search must be a string', function () {
    foreach (['search[]=x', 'search[a]=x', 'search[][]=x'] as $query) {
        $response = $this->getJson('/api/products?' . $query);
        $response->assertUnprocessable()->assertJsonValidationErrors('search');
        expect($response->getContent())->not->toContain('Array to string');
    }
});

test('string and empty searches keep working', function () {
    $this->getJson('/api/products?search=glass')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Glass pipe');
    $this->getJson('/api/products?search=')->assertOk()->assertJsonPath('meta.total', 26);
    $this->getJson('/api/products?search=paper&per_page=10&sort=name_asc')
        ->assertOk()->assertJsonPath('meta.total', 25)->assertJsonPath('data.0.name', 'Rolling paper 01');
});
