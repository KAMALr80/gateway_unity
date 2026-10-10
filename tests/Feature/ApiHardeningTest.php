<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function hardeningOrderPayload(User $user, array $items, array $extra = []): array
{
    $payload = ['items' => $items, 'billing_email' => $user->email];
    foreach (['first_name' => 'Jane', 'last_name' => 'Doe', 'address_1' => '1 Main St', 'city' => 'Town', 'postcode' => '12345', 'country' => 'US', 'phone' => '5550000'] as $k => $v) {
        $payload["billing_{$k}"]  = $v;
        $payload["shipping_{$k}"] = $v;
    }

    return array_merge($payload, $extra);
}

test('order totals ignore discount, shipping, tax and currency sent by the client', function () {
    Queue::fake();
    $user    = User::factory()->create(['approval_status' => 'approved']);
    $product = Product::create(['name' => 'Priced', 'sku' => 'PRICED-1', 'regular_price' => '25.00', 'is_active' => true, 'in_stock' => true]);

    $this->actingAs($user, 'api')->postJson('/api/orders', hardeningOrderPayload($user, [
        ['product_id' => $product->id, 'quantity' => 2],
    ], ['discount_total' => 49.99, 'shipping_total' => 15, 'total_tax' => 0, 'currency' => 'EUR']))->assertCreated();

    $order = Order::firstOrFail();
    expect((float) $order->line_total)->toBe(50.0)
        ->and((float) $order->discount_total)->toBe(0.0)
        ->and((float) $order->total)->toBe(50.0)
        ->and($order->currency)->toBe('USD');
});

test('order quantities and duplicate lines are validated', function () {
    $user    = User::factory()->create(['approval_status' => 'approved']);
    $product = Product::create(['name' => 'Q', 'sku' => 'Q-1', 'regular_price' => '1.00', 'is_active' => true, 'in_stock' => true]);

    $this->actingAs($user, 'api')->postJson('/api/orders', hardeningOrderPayload($user, [
        ['product_id' => $product->id, 'quantity' => 100000],
    ]))->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');

    $this->actingAs($user, 'api')->postJson('/api/orders', hardeningOrderPayload($user, [
        ['product_id' => $product->id, 'quantity' => 1],
        ['product_id' => $product->id, 'quantity' => 1],
    ]))->assertStatus(422);
});

test('login is rate limited per account and IP', function () {
    $user = User::factory()->create(['approval_status' => 'approved']);

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertStatus(401);
    }

    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertStatus(429);
});

test('password reset e-mails are rate limited', function () {
    foreach (range(1, 5) as $_) {
        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
    }

    $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])->assertStatus(429);
});

test('internal sync endpoints reject a missing or wrong key', function () {
    config(['services.erp.internal_key' => 'correct-key']);

    $this->postJson('/internal/brands/sync', [])->assertUnauthorized();
    $this->postJson('/internal/brands/sync', [], ['X-Internal-Key' => 'wrong-key'])->assertUnauthorized();
});

test('internal sync endpoints stay closed when no key is configured', function () {
    config(['services.erp.internal_key' => null]);

    $this->postJson('/internal/brands/sync', [], ['X-Internal-Key' => ''])->assertUnauthorized();
});

test('users cannot update another users profile or escalate their role', function () {
    [$user, $other] = [User::factory()->create(['approval_status' => 'pending']), User::factory()->create()];

    $this->actingAs($user, 'api')->patchJson("/api/users/{$other->id}", ['name' => 'Hacked'])->assertForbidden();

    $this->actingAs($user, 'api')->patchJson("/api/users/{$user->id}", [
        'name' => 'Me', 'is_admin' => true, 'approval_status' => 'approved',
    ])->assertOk();

    $user->refresh();
    expect($user->name)->toBe('Me')
        ->and((bool) $user->is_admin)->toBeFalse()
        ->and($user->approval_status)->not->toBe('approved');
});

test('non numeric ids are a 404, not a server error', function () {
    $this->getJson('/api/products/abc')->assertNotFound();
    $this->getJson('/api/categories/abc')->assertNotFound();
    $this->getJson('/api/brands/abc')->assertNotFound();
});

test('an order is still confirmed when the ERP sync cannot be queued', function () {
    $user    = User::factory()->create(['approval_status' => 'approved']);
    $product = Product::create(['name' => 'Erp', 'sku' => 'ERP-1', 'regular_price' => '3.00', 'is_active' => true, 'in_stock' => true]);
    $this->mock(\Illuminate\Contracts\Bus\Dispatcher::class)
        ->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));

    $this->actingAs($user, 'api')->postJson('/api/orders', hardeningOrderPayload($user, [
        ['product_id' => $product->id, 'quantity' => 1],
    ]))->assertCreated();

    expect(Order::count())->toBe(1);
});

test('ids longer than PHP int are a 404, and 404 bodies do not reveal model names', function () {
    $this->getJson('/api/products/99999999999999999999')->assertNotFound();
    $this->getJson('/api/brands/99999999999999999999')->assertNotFound();
    $this->getJson('/api/products/999999')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
});

test('responses carry baseline security headers and no PHP banner', function () {
    $response = $this->getJson('/api/products');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeaderMissing('X-Powered-By');
});

test('a failed ERP sync throws so the queue retries it with backoff', function () {
    $user  = User::factory()->create(['approval_status' => 'approved']);
    $order = Order::create([
        'invoice_no' => 'ORD-ERP-1', 'order_key' => 'order_test', 'created_via' => 'rest-api', 'transaction_date' => now(),
        'user_id' => $user->id, 'status' => 'pending', 'payment_status' => 'due', 'currency' => 'USD',
        'line_total' => 1, 'discount_total' => 0, 'shipping_total' => 0, 'total_tax' => 0, 'total' => 1,
        'billing_first_name' => 'A', 'billing_last_name' => 'B', 'billing_address_1' => '1', 'billing_city' => 'C', 'billing_postcode' => '1',
        'billing_country' => 'US', 'billing_email' => 'a@b.test', 'billing_phone' => '1', 'shipping_first_name' => 'A', 'shipping_last_name' => 'B',
        'shipping_address_1' => '1', 'shipping_city' => 'C', 'shipping_postcode' => '1', 'shipping_country' => 'US', 'sync_status' => 'pending',
    ]);
    config(['services.erp.order_webhook_url' => 'https://erp.invalid/orders']);
    \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::sequence()
        ->push('down', 503)
        ->push(['erp_order_id' => 77], 200)]);

    expect(fn () => (new \App\Jobs\SendOrderToErp($order))->handle(app(\App\Services\ErpOrderService::class)))
        ->toThrow(RuntimeException::class);
    expect($order->fresh()->sync_attempts)->toBe(1)
        ->and($order->fresh()->sync_status)->toBe('pending');

    (new \App\Jobs\SendOrderToErp($order->fresh()))->handle(app(\App\Services\ErpOrderService::class));
    expect($order->fresh()->sync_status)->toBe('synced');
});

test('erp:retry-orders re-queues stale unsynced orders only', function () {
    \Illuminate\Support\Facades\Queue::fake();
    $user = User::factory()->create(['approval_status' => 'approved']);
    $make = fn (string $status, $attemptAt, string $key) => Order::create([
        'invoice_no' => "ORD-{$key}", 'order_key' => "order_{$key}", 'created_via' => 'rest-api', 'transaction_date' => now(),
        'user_id' => $user->id, 'status' => 'pending', 'payment_status' => 'due', 'currency' => 'USD',
        'line_total' => 1, 'discount_total' => 0, 'shipping_total' => 0, 'total_tax' => 0, 'total' => 1,
        'billing_first_name' => 'A', 'billing_last_name' => 'B', 'billing_address_1' => '1', 'billing_city' => 'C', 'billing_postcode' => '1',
        'billing_country' => 'US', 'billing_email' => 'a@b.test', 'billing_phone' => '1', 'shipping_first_name' => 'A', 'shipping_last_name' => 'B',
        'shipping_address_1' => '1', 'shipping_city' => 'C', 'shipping_postcode' => '1', 'shipping_country' => 'US',
        'sync_status' => $status, 'last_sync_attempt_at' => $attemptAt,
    ]);
    $stale  = $make('pending', now()->subHours(2), 'stale');
    $make('pending', now()->subMinutes(5), 'recent');
    $make('synced', now()->subHours(2), 'synced');
    $failed = $make('failed', now()->subHours(2), 'failed');

    $this->artisan('erp:retry-orders')->assertSuccessful();
    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendOrderToErp::class, 1);
    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendOrderToErp::class, fn ($job) => $job->order->is($stale));

    $this->artisan('erp:retry-orders --include-failed')->assertSuccessful();
    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendOrderToErp::class, fn ($job) => $job->order->is($failed));
});

test('the API root answers with a simple JSON status', function () {
    foreach (['/api', '/api/'] as $path) {
        $this->getJson($path)->assertOk()->assertExactJson(['success' => true, 'message' => 'API is running']);
    }

    // existing routes are untouched
    $this->getJson('/api/does-not-exist')->assertNotFound();
    $this->postJson('/api/auth/login', [])->assertStatus(422);
});

test('the API root is covered by CORS for the configured frontend origin', function () {
    config(['cors.allowed_origins' => ['https://frontend.example']]);

    $this->withHeaders(['Origin' => 'https://frontend.example'])->getJson('/api')
        ->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://frontend.example');
});

test('the API root never echoes an unlisted origin', function () {
    config(['cors.allowed_origins' => ['https://frontend.example']]);

    $response = $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api')->assertOk();

    // the library answers with the one configured origin, which a browser on evil.example will reject
    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example');
});