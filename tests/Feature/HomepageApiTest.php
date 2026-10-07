<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\HomepageConfig;
use App\Models\Product;
use App\Models\User;
use App\Support\CatalogScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A request as a fresh server process sees it: no user left over from the last one. */
function homepageAs($test, ?string $token = null)
{
    app('auth')->forgetGuards();
    app('tymon.jwt')->unsetToken();
    CatalogScope::flush();

    return $token ? $test->withToken($token) : $test->withHeaders(['Authorization' => '']);
}

beforeEach(function () {
    $bags    = Category::create(['erp_category_id' => 333, 'name' => 'BAGS', 'is_active' => true]);
    $tobacco = Category::create(['erp_category_id' => 100, 'name' => 'PIPE TOBACCO', 'is_active' => true]);
    $bagCo   = Brand::create(['erp_brand_id' => 1, 'name' => 'BagCo', 'is_active' => true]);
    $largo   = Brand::create(['erp_brand_id' => 2, 'name' => 'Largo', 'is_active' => true]);

    $this->bag  = Product::create(['name' => 'SHOPPING BAG', 'regular_price' => 5, 'is_active' => true, 'in_stock' => true, 'category_id' => $bags->id, 'brand_id' => $bagCo->id]);
    $this->pipe = Product::create(['name' => 'PIPE TOBACCO 16OZ', 'regular_price' => 9, 'is_active' => true, 'in_stock' => true, 'category_id' => $tobacco->id, 'brand_id' => $largo->id]);

    $config = HomepageConfig::create(['client_key' => 'mobile-app', 'label' => 'Unity Wholesale app', 'is_active' => true, 'site_settings' => ['site_name' => 'Unity Wholesale']]);
    $hero = $config->sections()->create(['type' => 'hero', 'title' => 'Home slider', 'sort_order' => 0, 'is_active' => true, 'settings' => ['interval_ms' => 5000]]);
    $hero->items()->create(['kind' => 'slide', 'title' => 'Fall deals', 'desktop_image_external_url' => 'https://cdn.test/hero.jpg', 'mobile_image_external_url' => 'https://cdn.test/hero-m.jpg', 'alt_text' => 'Fall deals', 'link_url' => '/categories/' . $bags->id, 'sort_order' => 0, 'is_active' => true]);
    $hero->items()->create(['kind' => 'slide', 'title' => 'Hidden', 'desktop_image_external_url' => 'https://cdn.test/off.jpg', 'sort_order' => 1, 'is_active' => false]);
    $config->sections()->create(['type' => 'featured_category', 'title' => 'Tobacco', 'sort_order' => 1, 'is_active' => true, 'settings' => ['category_id' => $tobacco->id]]);
    $config->sections()->create(['type' => 'featured_category', 'title' => 'Bags', 'sort_order' => 2, 'is_active' => true, 'settings' => ['category_id' => $bags->id]]);
    $config->sections()->create(['type' => 'product_carousel', 'title' => 'Deals', 'sort_order' => 3, 'is_active' => true, 'settings' => ['product_ids' => [$this->pipe->id, $this->bag->id]]]);
    $config->sections()->create(['type' => 'brand_showcase', 'title' => 'Top brands', 'sort_order' => 4, 'is_active' => true, 'settings' => ['brand_ids' => [$largo->id, $bagCo->id]]]);
    $config->sections()->create(['type' => 'banner', 'title' => 'Switched off', 'sort_order' => 5, 'is_active' => false]);

    $this->customer = User::factory()->create(['approval_status' => 'approved']);
    $this->reviewer = User::factory()->create(['approval_status' => 'approved']);
    $this->reviewer->forceFill(['catalog_category_id' => $bags->id])->save();
});

it('returns the active sections in order, with their active items', function () {
    homepageAs($this)->getJson('/api/homepage?client=mobile-app')
        ->assertOk()
        ->assertJsonPath('client', 'mobile-app')
        ->assertJsonPath('site.site_name', 'Unity Wholesale')
        ->assertJsonCount(5, 'sections')
        ->assertJsonPath('sections.0.type', 'hero')
        ->assertJsonPath('sections.0.settings.interval_ms', 5000)
        ->assertJsonCount(1, 'sections.0.items')
        ->assertJsonPath('sections.0.items.0.mobile_image_url', 'https://cdn.test/hero-m.jpg');
});

it('formats homepage products like /api/products, with prices only for approved accounts', function () {
    $guest = homepageAs($this)->getJson('/api/homepage?client=mobile-app');
    $guest->assertJsonPath('sections.2.data.products.0.name', 'SHOPPING BAG')
        ->assertJsonPath('sections.2.data.products.0.prices_visible', false)
        ->assertJsonPath('sections.2.data.products.0.regular_price', null)
        ->assertJsonPath('sections.2.data.category.name', 'BAGS');

    homepageAs($this, auth('api')->login($this->customer))->getJson('/api/homepage?client=mobile-app')
        ->assertJsonPath('sections.2.data.products.0.prices_visible', true)
        ->assertJsonPath('sections.2.data.products.0.regular_price', '5.00');
});

it('keeps a catalog-limited account inside its catalog', function () {
    $response = homepageAs($this, auth('api')->login($this->reviewer))->getJson('/api/homepage?client=mobile-app')->assertOk();

    expect(collect($response->json('sections'))->pluck('title')->all())->toBe(['Home slider', 'Bags', 'Deals', 'Top brands'])
        ->and($response->json('sections.2.data.product_ids'))->toBe([$this->bag->id])
        ->and(collect($response->json('sections.3.data.brands'))->pluck('name')->all())->toBe(['BagCo']);

    // Everyone else still gets the whole homepage.
    $everyone = homepageAs($this, auth('api')->login($this->customer))->getJson('/api/homepage?client=mobile-app');
    expect($everyone->json('sections'))->toHaveCount(5)
        ->and($everyone->json('sections.3.data.product_ids'))->toBe([$this->pipe->id, $this->bag->id]);
});

it('needs a known client key', function () {
    homepageAs($this)->getJson('/api/homepage')->assertStatus(422);
    homepageAs($this)->getJson('/api/homepage?client=nope')->assertNotFound();
});
