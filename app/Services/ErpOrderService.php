<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ErpOrderService
{
    private string $webhookUrl;
    private string $secret;

    public function __construct()
    {
        // Cast: an unset key must not stop every order screen from loading.
        $this->webhookUrl = (string) config('services.erp.order_webhook_url');
        $this->secret     = (string) config('services.erp.webhook_secret');
    }

    /**
     * Returns true once the ERP has the order, false when the ERP refused it
     * for good (422: it can't map the order; marked failed, not retried).
     *
     * Anything worth another try (no connection, a timeout, 5xx, or 401 while
     * the ERP's secret is being fixed) throws, so SendOrderToErp's tries and
     * backoff apply. A retry after a timeout is safe: the ERP matches
     * order_key and returns the sale it already made.
     */
    public function sendOrder(Order $order): bool
    {
        $payload   = $this->buildPayload($order);
        $signature = $this->sign($payload);

        try {
            $response = Http::withHeaders([
                'Content-Type'       => 'application/json',
                'X-Webhook-Secret'   => $signature,
            ])
            ->timeout(30)
            ->post($this->webhookUrl, $payload);
        } catch (\Throwable $e) {
            $this->recordFailedAttempt($order, $e->getMessage());
            throw $e;
        }

        if ($response->successful()) {
            $body = $response->json();

            $order->update([
                'sync_status'          => 'synced',
                'erp_order_id'         => $body['erp_order_id'] ?? null,
                'sync_attempts'        => $order->sync_attempts + 1,
                'last_sync_attempt_at' => now(),
                'synced_at'            => now(),
                'sync_error'           => null,
            ]);

            return true;
        }

        $error = "ERP returned HTTP {$response->status()}: " . Str::limit($response->body(), 500);

        if ($response->status() === 422) {
            $this->recordFailedAttempt($order, $error, 'failed');
            return false;
        }

        $this->recordFailedAttempt($order, $error);
        throw new \RuntimeException($error);
    }

    /**
     * The order as the ERP has it now (edited by staff, paid, shipped...), or
     * null when the ERP can't be reached or doesn't know it. A short timeout:
     * the app is waiting on this.
     *
     * GET {ERP_ORDER_WEBHOOK_URL}/{erp_order_id}?order_key=... A GET has no
     * body to sign, so the signature covers "{timestamp}\n{id}\n{order_key}";
     * the ERP accepts it for five minutes.
     */
    public function fetchOrder(Order $order): ?array
    {
        if (empty($order->erp_order_id) || empty($order->order_key) || $this->webhookUrl === '') {
            return null;
        }

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . "\n" . $order->erp_order_id . "\n" . $order->order_key, $this->secret);

        try {
            $response = Http::withHeaders([
                'X-Webhook-Timestamp' => $timestamp,
                'X-Webhook-Secret'    => $signature,
            ])
            ->acceptJson()
            ->timeout(8)
            ->get(rtrim($this->webhookUrl, '/') . '/' . $order->erp_order_id, ['order_key' => $order->order_key]);
        } catch (\Throwable $e) {
            Log::warning('ERP order read failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            return null;
        }

        if (!$response->successful() || !is_array($response->json('items'))) {
            Log::warning('ERP order read failed', ['order_id' => $order->id, 'status' => $response->status()]);
            return null;
        }

        return $response->json();
    }

    private function buildPayload(Order $order): array
    {
        $order->loadMissing(['items', 'user']);

        return [
            'id'                   => $order->id,
            'invoice_no'           => $order->invoice_no,
            'order_key'            => $order->order_key,
            'created_via'          => $order->created_via,
            'transaction_date'     => $order->transaction_date?->toIso8601String(),
            'status'               => $order->status,
            'payment_status'       => $order->payment_status,
            'currency'             => $order->currency,
            'line_total'           => $order->line_total,
            'discount_total'       => $order->discount_total,
            'shipping_total'       => $order->shipping_total,
            'total_tax'            => $order->total_tax,
            'total'                => $order->total,
            'customer_id'          => $order->erp_customer_id,
            'customer_note'        => $order->customer_note,
            'customer_ip_address'  => $order->customer_ip_address,
            'billing_first_name'   => $order->billing_first_name,
            'billing_last_name'    => $order->billing_last_name,
            'billing_company'      => $order->billing_company,
            'billing_address_1'    => $order->billing_address_1,
            'billing_address_2'    => $order->billing_address_2,
            'billing_city'         => $order->billing_city,
            'billing_state'        => $order->billing_state,
            'billing_postcode'     => $order->billing_postcode,
            'billing_country'      => $order->billing_country,
            'billing_email'        => $order->billing_email,
            'billing_phone'        => $order->billing_phone,
            'shipping_first_name'  => $order->shipping_first_name,
            'shipping_last_name'   => $order->shipping_last_name,
            'shipping_company'     => $order->shipping_company,
            'shipping_address_1'   => $order->shipping_address_1,
            'shipping_address_2'   => $order->shipping_address_2,
            'shipping_city'        => $order->shipping_city,
            'shipping_state'       => $order->shipping_state,
            'shipping_postcode'    => $order->shipping_postcode,
            'shipping_country'     => $order->shipping_country,
            'meta_data'            => $order->meta_data ?? [],
            'line_items'           => $order->items->map(fn($item) => [
                'product_id'     => $item->erp_product_id,
                'name'           => $item->name,
                'sku'            => $item->sku,
                'quantity'       => $item->quantity,
                'unit_price'     => $item->unit_price,
                'unit_tax'       => $item->unit_tax,
                'total'          => $item->total,
            ])->toArray(),
        ];
    }

    private function sign(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload), $this->secret);
    }

    /** 'pending' keeps the order in line for another try; 'failed' ends it. */
    private function recordFailedAttempt(Order $order, string $error, string $status = 'pending'): void
    {
        Log::error('ERP order sync failed', [
            'order_id' => $order->id,
            'attempt'  => $order->sync_attempts + 1,
            'error'    => $error,
        ]);

        $order->update([
            'sync_status'           => $status,
            'sync_attempts'         => $order->sync_attempts + 1,
            'last_sync_attempt_at'  => now(),
            'sync_error'            => $error,
        ]);
    }
}