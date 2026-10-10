<?php

namespace App\Services\HomepageTransfer;

use App\Http\Controllers\HomepageController;
use App\Models\HomepageConfig;
use Illuminate\Http\Request;
use Throwable;

/**
 * After a commit, builds the homepage the same way the public API does and checks it against what was intended.
 * The homepage payload is assembled per request (there is no server-side homepage cache to refresh), so this
 * is the verification that the live endpoint serves the new configuration.
 */
class HomepagePostImportVerifier
{
    /**
     * @param  array|null  $intended  the portable state that should now be live, or null when no homepage should exist
     * @return array{ok: bool, checks: array<int, array{name: string, ok: bool, detail: string}>}
     */
    public function verify(string $clientKey, ?array $intended): array
    {
        $checks = [];
        $expectActive = $intended !== null && $intended['is_active'];

        try {
            $response = app(HomepageController::class)->show(Request::create('/api/homepage', 'GET', ['client' => $clientKey]));
            $status = $response->getStatusCode();
            $payload = $status === 200 ? json_decode($response->getContent(), true) : null;
        } catch (Throwable $e) {
            report($e);
            $status = 500;
            $payload = null;
        }

        $checks[] = $this->check('public API status', $status === ($expectActive ? 200 : 404), "HTTP $status, expected ".($expectActive ? 200 : 404));

        if ($expectActive && is_array($payload)) {
            $served = array_map(fn ($s) => $s['type'], $payload['sections'] ?? []);
            $expected = collect($intended['sections'])->where('is_active', true)->sortBy('sort_order')->pluck('type')->values()->all();

            $checks[] = $this->check('client', ($payload['client'] ?? null) === $clientKey, 'the API answered for '.($payload['client'] ?? '?'));
            $checks[] = $this->check('section order', $served === $expected, count($served).' sections served, '.count($expected).' expected');
        }

        if ($intended === null) {
            $checks[] = $this->check('homepage removed', ! HomepageConfig::where('client_key', $clientKey)->exists(), '');
        }

        return ['ok' => collect($checks)->every(fn ($c) => $c['ok']), 'checks' => $checks];
    }

    private function check(string $name, bool $ok, string $detail): array
    {
        return ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }
}
