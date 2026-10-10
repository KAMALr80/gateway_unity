<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\HomepageConfig;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Every page of the admin panel opens for an admin, with real records behind the detail pages,
 * so a broken controller, prop or view shows up here instead of in production.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->admin = User::factory()->create(['is_admin' => true, 'approval_status' => 'approved']);
    $this->customer = User::factory()->create(['approval_status' => 'pending']);
    $this->category = Category::create(['erp_category_id' => 1, 'name' => 'Wraps', 'is_active' => true]);
    $this->brand = Brand::create(['erp_brand_id' => 1, 'name' => 'BagCo', 'is_active' => true]);
    $this->product = Product::create(['name' => 'Wrap', 'regular_price' => 5, 'is_active' => true, 'in_stock' => true, 'category_id' => $this->category->id, 'brand_id' => $this->brand->id]);
    $this->config = HomepageConfig::create(['client_key' => 'unity-web', 'label' => 'Unity web', 'is_active' => true]);
    $this->order = Order::create([
        'invoice_no' => 'ORD000001', 'order_key' => 'order_smoke', 'created_via' => 'rest-api', 'transaction_date' => now(),
        'user_id' => $this->customer->id, 'status' => 'pending', 'payment_status' => 'due', 'currency' => 'USD',
        'line_total' => 5, 'discount_total' => 0, 'shipping_total' => 0, 'total_tax' => 0, 'total' => 5,
        'billing_first_name' => 'A', 'billing_last_name' => 'B', 'billing_address_1' => '1', 'billing_city' => 'C', 'billing_postcode' => '1',
        'billing_country' => 'US', 'billing_email' => 'a@b.test', 'billing_phone' => '1', 'shipping_first_name' => 'A', 'shipping_last_name' => 'B',
        'shipping_address_1' => '1', 'shipping_city' => 'C', 'shipping_postcode' => '1', 'shipping_country' => 'US',
    ]);
});

it('opens every admin page', function () {
    $pages = [
        '/admin', '/admin/products', "/admin/products/{$this->product->id}", '/admin/categories', "/admin/categories/{$this->category->id}",
        '/admin/brands', "/admin/brands/{$this->brand->id}", '/admin/orders', "/admin/orders/{$this->order->id}",
        '/admin/users', '/admin/users/create', "/admin/users/{$this->customer->id}", "/admin/users/{$this->customer->id}/edit",
        '/admin/homepage', '/admin/homepage/create', "/admin/homepage/{$this->config->id}/edit", '/admin/homepage-transfer',
        '/settings/profile',
    ];

    foreach ($pages as $page) {
        $status = $this->actingAs($this->admin)->get($page)->status();
        expect($status)->toBe(200, "$page answered $status");
    }

    // Security settings first ask for the password again (Fortify password confirmation), then open.
    $this->actingAs($this->admin)->get('/settings/security')->assertRedirect(route('password.confirm'));
    $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/security')->assertOk();
});

it('keeps every admin page away from customers', function () {
    foreach (['/admin', '/admin/products', '/admin/orders', '/admin/users', '/admin/homepage'] as $page) {
        expect($this->actingAs($this->customer)->get($page)->status())->not->toBe(200, "$page is open to a customer");
    }
});
