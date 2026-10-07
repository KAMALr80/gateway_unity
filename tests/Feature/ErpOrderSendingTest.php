<?php

use App\Jobs\SendOrderToErp;
use App\Models\Order;
use App\Services\ErpOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.erp.order_webhook_url' => 'https://erp.test/gateway/orders',
        'services.erp.webhook_secret'    => 'whsec-test',
    ]);
});

function erpBoundOrder(array $attributes = []): Order
{
    $order = Order::create(['invoice_no' => 'ORD000007', 'order_key' => 'order_' . Str::random(13), 'total' => 14]);
    $order->forceFill($attributes)->saveQuietly();

    return $order->fresh();
}

it('sends the order signed over its exact body and records the ERP sale', function () {
    Http::fake(['erp.test/*' => Http::response(['erp_order_id' => 501, 'invoice_no' => 'INV-0042'], 201)]);
    $order = erpBoundOrder();

    expect(app(ErpOrderService::class)->sendOrder($order))->toBeTrue();

    $order->refresh();
    expect($order->sync_status)->toBe('synced')
        ->and($order->erp_order_id)->toBe(501)
        ->and($order->sync_attempts)->toBe(1)
        ->and($order->sync_error)->toBeNull();

    // What the ERP checks: an HMAC of the raw bytes it receives.
    Http::assertSent(fn (Request $request) => $request->header('X-Webhook-Secret')[0] === hash_hmac('sha256', $request->body(), 'whsec-test')
        && $request['order_key'] === $order->order_key);
});

it('stops at once when the ERP refuses the order (422)', function () {
    Http::fake(['erp.test/*' => Http::response(['message' => 'Line 1: GROUP#5 is a product group'], 422)]);
    $order = erpBoundOrder();

    expect(app(ErpOrderService::class)->sendOrder($order))->toBeFalse();

    $order->refresh();
    expect($order->sync_status)->toBe('failed')
        ->and($order->sync_attempts)->toBe(1)
        ->and($order->sync_error)->toContain('GROUP#5');
});

it('throws on errors worth retrying and keeps the order pending', function (int $status) {
    Http::fake(['erp.test/*' => Http::response(['message' => 'nope'], $status)]);
    $order = erpBoundOrder();

    expect(fn () => app(ErpOrderService::class)->sendOrder($order))->toThrow(RuntimeException::class);

    $order->refresh();
    expect($order->sync_status)->toBe('pending')
        ->and($order->sync_attempts)->toBe(1)
        ->and($order->sync_error)->toContain((string) $status);
})->with(['server error' => 500, 'signature not accepted yet' => 401]);

it('throws on a connection failure and keeps the order pending', function () {
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));
    $order = erpBoundOrder();

    expect(fn () => app(ErpOrderService::class)->sendOrder($order))->toThrow(ConnectionException::class);
    expect($order->fresh()->sync_status)->toBe('pending');
});

it('retries with backoff and never leaves an order syncing', function () {
    Http::fake(['erp.test/*' => Http::response('down', 503)]);
    $order = erpBoundOrder();
    $job   = new SendOrderToErp($order);

    expect($job->tries)->toBe(3)->and($job->backoff)->toBe([60, 300, 900]);
    expect(fn () => $job->handle(app(ErpOrderService::class)))->toThrow(RuntimeException::class);

    DB::table('orders')->where('id', $order->id)->update(['sync_status' => 'syncing']);
    $job->failed(new RuntimeException('down'));

    expect($order->fresh()->sync_status)->toBe('pending');
});

it('resends stuck orders and leaves the rest alone', function () {
    Queue::fake();
    $old = now()->subMinutes(10);

    $fresh        = erpBoundOrder();
    $neverSent    = erpBoundOrder(['created_at' => $old]);
    $recentTry    = erpBoundOrder(['created_at' => $old, 'last_sync_attempt_at' => now()->subMinutes(5), 'sync_attempts' => 1]);
    $staleTry     = erpBoundOrder(['created_at' => $old, 'last_sync_attempt_at' => now()->subMinutes(40), 'sync_attempts' => 3]);
    $deadWorker   = erpBoundOrder(['created_at' => $old, 'sync_status' => 'syncing']);
    $exhausted    = erpBoundOrder(['created_at' => $old, 'sync_attempts' => 24]);
    $synced       = erpBoundOrder(['created_at' => $old, 'sync_status' => 'synced']);
    $refused      = erpBoundOrder(['created_at' => $old, 'sync_status' => 'failed', 'sync_attempts' => 1]);
    DB::table('orders')->where('id', $deadWorker->id)->update(['updated_at' => now()->subMinutes(20)]);

    $this->artisan('orders:resend-stuck')->assertSuccessful();

    $resent = Queue::pushed(SendOrderToErp::class)->map(fn ($job) => $job->order->id)->sort()->values()->all();
    expect($resent)->toBe(collect([$neverSent->id, $staleTry->id, $deadWorker->id])->sort()->values()->all());

    expect($exhausted->fresh()->sync_status)->toBe('failed')
        ->and($synced->fresh()->sync_status)->toBe('synced')
        ->and($refused->fresh()->sync_status)->toBe('failed')
        ->and($fresh->fresh()->sync_status)->toBe('pending')
        ->and($recentTry->fresh()->sync_status)->toBe('pending');
});

it('schedules the order queue every minute and the sweep every ten', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('queue:work --stop-when-empty')
        ->expectsOutputToContain('orders:resend-stuck')
        ->assertSuccessful();
});
