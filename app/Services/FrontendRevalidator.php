<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the storefront to drop its cached homepage once the homepage was changed here (admin edits, imports,
 * restores). Without this the storefront keeps the old homepage for up to five minutes.
 *
 * queue() only marks the request as needing a refresh; the single call to the storefront is made after the response
 * has been sent, so saving in the admin never waits on, or fails because of, the storefront. Every failure is logged
 * and swallowed: the storefront's own cache expiry still catches up.
 */
class FrontendRevalidator
{
    private bool $queued = false;

    public function configured(): bool
    {
        return $this->url() !== '' && $this->secret() !== '';
    }

    /** Ask for one refresh at the end of this request/job, however many homepage rows change meanwhile. */
    public function queue(): void
    {
        if ($this->queued || ! $this->configured()) {
            return;
        }

        $this->queued = true;
        app()->terminating(fn () => $this->send());
    }

    public function send(): bool
    {
        $this->queued = false;

        if (! $this->configured()) {
            return false;
        }

        try {
            $response = Http::timeout(8)->acceptJson()
                ->withHeaders(['x-revalidate-secret' => $this->secret()])
                ->post($this->url().'/api/revalidate');

            if (! $response->successful()) {
                Log::warning('Storefront cache refresh was rejected.', ['status' => $response->status()]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('Storefront cache refresh failed.', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function url(): string
    {
        return rtrim((string) config('app.frontend_url'), '/');
    }

    private function secret(): string
    {
        return (string) config('services.frontend.revalidate_secret');
    }
}
