<?php

use App\Jobs\SendOrderToErp;
use App\Models\Order;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 | Orders to the ERP. The server's cron runs schedule:run every minute; this
 | works the queue (SendOrderToErp) until nothing is due, then exits, so a
 | worker never has to stay running.
 */
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command('orders:resend-stuck')
    ->everyTenMinutes()
    ->withoutOverlapping();

/*
 | An order can stall on its way to the ERP: the queue wasn't running when it
 | was placed, its retries ran out while the ERP was down, or a worker died
 | mid-send. This sends those again. The ERP matches order_key, so an order it
 | already has comes back as the same sale, never a second one.
 */
Artisan::command('orders:resend-stuck {--max-attempts=24}', function () {
    $max = (int) $this->option('max-attempts');

    $unstuck = Order::where('sync_status', 'syncing')
        ->where('updated_at', '<', now()->subMinutes(15))
        ->update(['sync_status' => 'pending']);

    $gaveUp = Order::where('sync_status', 'pending')
        ->where('sync_attempts', '>=', $max)
        ->update(['sync_status' => 'failed']);

    $due = Order::where('sync_status', 'pending')
        ->where('sync_attempts', '<', $max)
        // Leave fresh orders to the job dispatched when they were placed.
        ->where('created_at', '<', now()->subMinutes(5))
        ->where(function ($q) {
            $q->whereNull('last_sync_attempt_at')
              ->orWhere('last_sync_attempt_at', '<', now()->subMinutes(30));
        })
        ->get();

    foreach ($due as $order) {
        SendOrderToErp::dispatch($order);
    }

    $this->info("Resent {$due->count()}, unstuck {$unstuck}, gave up on {$gaveUp}.");
})->purpose('Send again orders stuck on their way to the ERP');
