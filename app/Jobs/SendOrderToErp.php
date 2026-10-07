<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\ErpOrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendOrderToErp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    // Backoff in seconds between retries: 1min, 5min, 15min
    public array $backoff = [60, 300, 900];

    public function __construct(public Order $order) {}

    public function handle(ErpOrderService $erpService): void
    {
        // Skip if already synced (e.g. duplicate job dispatch)
        if ($this->order->isSynced()) {
            return;
        }

        $this->order->update([
            'sync_status' => 'syncing',
        ]);

        $erpService->sendOrder($this->order);
    }

    /**
     * The last try failed. The order goes back to 'pending' (never left in
     * 'syncing') so orders:resend-stuck picks it up later; only a refusal by
     * the ERP (422, handled in ErpOrderService) marks it 'failed'.
     */
    public function failed(\Throwable $e): void
    {
        $this->order->refresh();

        if ($this->order->sync_status === 'syncing') {
            $this->order->update(['sync_status' => 'pending', 'sync_error' => $e->getMessage()]);
        }
    }
}