<?php

use App\Models\HomepageConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** The admin form saves every header/menu setting the storefront reads, including the newer ones. */
beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->config = HomepageConfig::create([
        'client_key' => 'unity-web', 'label' => 'Unity web', 'is_active' => true,
        'site_settings' => [
            'site_name' => 'Unity Wholesale',
            'site_tagline' => 'Smoke • Vape',
            'nav_links_before' => [['label' => 'Home', 'url' => '/']],
            'all_categories' => [['label' => 'CBD', 'url' => '/category/13']],
            'nav_groups' => [['label' => 'Vape Shop', 'keywords' => [], 'names' => ['E-LIQUID', 'JUUL']]],
        ],
    ]);
});

function saveSettings(array $siteSettings)
{
    return test()->actingAs(test()->admin)->patch("/admin/homepage/".test()->config->id, [
        'client_key' => 'unity-web', 'label' => 'Unity web', 'is_active' => true, 'site_settings' => $siteSettings,
    ]);
}

it('keeps settings the form did not send', function () {
    saveSettings(['site_name' => 'Unity Wholesale', 'nav_groups' => [['label' => 'Vape Shop', 'keywords' => [], 'names' => ['E-LIQUID', 'JUUL']]]])->assertSessionHasNoErrors();

    $settings = $this->config->fresh()->site_settings;
    expect($settings['site_tagline'])->toBe('Smoke • Vape')
        ->and($settings['nav_links_before'])->toBe([['label' => 'Home', 'url' => '/']])
        ->and($settings['all_categories'])->toBe([['label' => 'CBD', 'url' => '/category/13']]);
});

it('saves menu category names, tagline and link lists', function () {
    saveSettings([
        'site_name' => 'Unity Wholesale',
        'site_tagline' => 'Cigars • Cigarettes',
        'utility_message' => 'Welcome',
        'utility_links' => [['label' => 'About us', 'url' => '/about']],
        'nav_links_before' => [['label' => 'Home', 'url' => '/'], ['label' => 'Convenience Store', 'url' => '/shop']],
        'nav_links' => [['label' => 'New Arrivals', 'url' => '/new']],
        'all_categories' => [],
        'nav_groups' => [['label' => 'Smoke Shop', 'keywords' => [], 'names' => ['ASHTRAYS', 'BUTANE']]],
    ])->assertSessionHasNoErrors();

    $settings = $this->config->fresh()->site_settings;
    expect($settings['nav_groups'][0]['names'])->toBe(['ASHTRAYS', 'BUTANE'])
        ->and($settings['site_tagline'])->toBe('Cigars • Cigarettes')
        ->and($settings['utility_message'])->toBe('Welcome')
        ->and($settings['nav_links_before'])->toHaveCount(2)
        ->and($settings['nav_links'])->toBe([['label' => 'New Arrivals', 'url' => '/new']])
        ->and($settings['all_categories'])->toBe([]);

    $this->getJson('/api/homepage?client=unity-web')->assertOk()
        ->assertJsonPath('site.site_tagline', 'Cigars • Cigarettes')
        ->assertJsonPath('site.nav_groups.0.names', ['ASHTRAYS', 'BUTANE']);
});
