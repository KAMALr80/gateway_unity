<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cart tax quotes from the ERP, which owns the tax rules: POST {ERP}/gateway/tax, signed like orders
 * (HMAC-SHA256 of the raw JSON body in X-Webhook-Secret). The ERP picks each line's rule the way its POS does
 * and applies it when the customer's state matches. Its state is the ERP customer's, else the one given here.
 */
class ErpTaxService
{
    private string $url;
    private string $secret;

    public function __construct()
    {
        $orderUrl     = (string) config('services.erp.order_webhook_url');
        $this->url    = (string) config('services.erp.tax_url')
            ?: (preg_match('#/orders/?$#', $orderUrl) ? preg_replace('#/orders/?$#', '/tax', $orderUrl) : '');
        $this->secret = (string) config('services.erp.webhook_secret');
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && $this->secret !== '';
    }

    /**
     * @param  list<array{product_id: string, quantity: int}>  $lines  ERP product ids
     * @return array{state: ?string, state_source: ?string, lines: list<array>, total_tax: float}|null
     *         null when the ERP can't be reached or answers badly
     */
    public function quote(User $user, array $lines, ?string $addressState): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $body = json_encode([
            'contact_id' => $user->erp_contact_id,
            'email'      => $user->email,
            'state'      => $addressState,
            'lines'      => $lines,
        ]);

        try {
            $response = Http::withHeaders(['X-Webhook-Secret' => hash_hmac('sha256', $body, $this->secret)])
                ->acceptJson()
                ->withBody($body, 'application/json')
                // Short: the cart screen is waiting on this.
                ->timeout(8)
                ->post($this->url);
        } catch (\Throwable $e) {
            Log::warning('ERP tax quote failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful() || ! is_array($response->json('lines'))) {
            Log::warning('ERP tax quote failed', ['user_id' => $user->id, 'status' => $response->status()]);

            return null;
        }

        return [
            'state'        => $response->json('state'),
            'state_source' => $response->json('state_source'),
            'lines'        => $response->json('lines'),
            'total_tax'    => (float) $response->json('total_tax', 0),
        ];
    }
}
