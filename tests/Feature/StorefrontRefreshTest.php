<?php

use App\Models\HomepageConfig;
use App\Models\HomepageSection;
use App\Models\User;
use App\Services\FrontendRevalidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/** Homepage edits make the storefront drop its cached homepage, so changes show at once instead of after ~5 minutes. */
beforeEach(function () {
    // Set up the data while the storefront refresh is still switched off, so only the code under test calls it.
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->config = HomepageConfig::create(['client_key' => 'unity-web', 'label' => 'Unity web', 'is_active' => true, 'site_settings' => ['site_name' => 'Unity']]);

    config(['app.frontend_url' => 'https://shop.example/', 'services.frontend.revalidate_secret' => 'top-secret']);
    Http::fake(['https://shop.example/*' => Http::response(['ok' => true])]);
});

function refreshCalls(): int
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/api/revalidate'))->count();
}

function saveHomepage(string $siteName)
{
    return test()->actingAs(test()->admin)->patch('/admin/homepage/'.test()->config->id, [
        'client_key' => 'unity-web', 'label' => 'Unity web', 'is_active' => true, 'site_settings' => ['site_name' => $siteName],
    ])->assertSessionHasNoErrors();
}

it('asks the storefront to refresh with the shared secret after a homepage edit', function () {
    saveHomepage('Unity Wholesale');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://shop.example/api/revalidate'
        && $request->method() === 'POST'
        && $request->hasHeader('x-revalidate-secret', 'top-secret'));
    expect(refreshCalls())->toBe(1);
});

it('refreshes once per request however many homepage rows change', function () {
    $a = HomepageSection::create(['homepage_config_id' => $this->config->id, 'type' => 'banner', 'title' => 'A', 'sort_order' => 0, 'is_active' => true]);
    $b = HomepageSection::create(['homepage_config_id' => $this->config->id, 'type' => 'banner', 'title' => 'B', 'sort_order' => 1, 'is_active' => true]);
    $before = refreshCalls();

    $this->actingAs($this->admin)->post("/admin/homepage/{$this->config->id}/sections/reorder", ['sections' => [
        ['id' => $a->id, 'sort_order' => 1], ['id' => $b->id, 'sort_order' => 0],
    ]])->assertRedirect();

    expect(refreshCalls() - $before)->toBe(1)
        ->and($b->fresh()->sort_order)->toBe(0);
});

it('does nothing when the secret or storefront address is not set', function () {
    config(['services.frontend.revalidate_secret' => null]);
    saveHomepage('No secret');

    config(['services.frontend.revalidate_secret' => 'top-secret', 'app.frontend_url' => '']);
    $this->config->update(['label' => 'No address']);

    expect(refreshCalls())->toBe(0)
        ->and(app(FrontendRevalidator::class)->configured())->toBeFalse();
});

it('never fails the admin save when the storefront rejects the call or is down', function () {
    Http::fake(['https://shop.example/*' => Http::response(['ok' => false], 401)]);
    saveHomepage('Still saved');
    expect($this->config->fresh()->site_settings['site_name'])->toBe('Still saved');

    Http::fake(['https://shop.example/*' => fn () => throw new ConnectionException('down')]);
    expect(app(FrontendRevalidator::class)->send())->toBeFalse();
});
