<?php

use App\Events\HomepageImported;
use App\Events\HomepageRestored;
use App\Models\Brand;
use App\Models\Category;
use App\Models\HomepageConfig;
use App\Models\HomepageImport;
use App\Models\HomepageSection;
use App\Models\HomepageSectionItem;
use App\Models\Product;
use App\Models\User;
use App\Services\HomepageTransfer\Canonical;
use App\Services\HomepageTransfer\HomepageBackup;
use App\Services\HomepageTransfer\HomepageExporter;
use App\Services\HomepageTransfer\HomepagePostImportVerifier;
use App\Services\HomepageTransfer\HomepageSnapshot;
use App\Services\HomepageTransfer\HomepageTransferException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const HP_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
const HP_URL = '/admin/homepage-transfer';

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->tmp = [];
});

afterEach(function () {
    foreach ($this->tmp as $file) {
        @unlink($file);
    }
    HomepageSectionItem::flushEventListeners();
});

// ---------------------------------------------------------------------------------------------------- fixtures

function hpPng(string $salt): string
{
    return base64_decode(HP_PNG).$salt;
}

/** The reference rows a homepage points at. Called again after hpWipe(), it yields the same business keys with NEW database ids. */
function hpReferences(): array
{
    $category = Category::create(['erp_category_id' => 501, 'name' => 'Hookah', 'is_active' => true]);
    $brands = [
        801 => Brand::create(['erp_brand_id' => 801, 'name' => 'Brand A', 'is_active' => true]),
        802 => Brand::create(['erp_brand_id' => 802, 'name' => 'Brand B', 'is_active' => true]),
    ];
    $products = [];
    foreach (range(1, 5) as $n) {
        $products["P-$n"] = Product::create(['erp_product_id' => "P-$n", 'name' => "Product $n", 'regular_price' => 10 + $n, 'is_active' => true, 'in_stock' => true]);
    }

    return compact('category', 'brands', 'products');
}

/** A realistic Unity homepage in this database: every section type, uploaded files, external URLs, references. */
function hpSource(string $client = 'unity'): array
{
    $refs = hpReferences();
    $disk = Storage::disk('public');

    $disk->put("homepage/$client/site/old-logo.png", hpPng('logo'));
    $config = HomepageConfig::create([
        'client_key' => $client, 'label' => 'Unity Wholesale', 'is_active' => true,
        'site_settings' => ['site_name' => 'Unity Wholesale', 'phone' => '+1 555 0100', 'nav_links' => [['label' => 'Shop', 'url' => '/shop']], 'internal_note' => 'not part of the homepage package'],
        'site_logo_path' => "homepage/$client/site/old-logo.png",
    ]);

    $make = function (string $type, string $title, array $settings = []) use ($config) {
        return $config->sections()->create(['type' => $type, 'title' => $title, 'is_active' => true, 'sort_order' => $config->sections()->count(), 'settings' => $settings]);
    };
    $item = fn (HomepageSection $s, array $a) => $s->items()->create($a + ['alt_text' => 'alt', 'sort_order' => $s->items()->count(), 'is_active' => true]);

    $hero = $make('hero', 'Welcome', ['interval_ms' => 6000, 'subtitle' => 'Wholesale for businesses']);
    $disk->put("homepage/$client/{$hero->id}/slide.png", hpPng('slide'));
    $item($hero, ['kind' => 'slide', 'title' => 'Slide 1', 'desktop_image_path' => "homepage/$client/{$hero->id}/slide.png", 'mobile_image_external_url' => 'https://img.example.test/m.jpg', 'link_url' => '/shop']);

    $make('product_carousel', 'Top picks', ['source' => 'manual', 'limit' => 7, 'product_ids' => [$refs['products']['P-3']->id, $refs['products']['P-1']->id, $refs['products']['P-2']->id]]);
    $make('product_carousel', 'New', ['source' => 'newest', 'limit' => 4, 'product_ids' => [$refs['products']['P-5']->id]]);
    $make('featured_category', 'Hookahs', ['category_id' => $refs['category']->id, 'product_limit' => 6]);

    $brandSection = $make('brand_showcase', 'Brands', ['brand_ids' => [$refs['brands'][802]->id, $refs['brands'][801]->id]]);
    $disk->put("homepage/$client/{$brandSection->id}/brands/a.png", hpPng('brand-a'));
    $item($brandSection, ['kind' => 'brand', 'title' => 'Brand A', 'desktop_image_path' => "homepage/$client/{$brandSection->id}/brands/a.png", 'link_url' => '/brands']);

    $catalog = $make('catalog_showcase', 'Catalog', ['eyebrow' => 'Catalog', 'heading_phrases' => ['Browse', 'Order']]);
    $disk->put("homepage/$client/{$catalog->id}/cover.png", hpPng('cover'));
    $disk->put("homepage/$client/{$catalog->id}/catalog.pdf", "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    $item($catalog, ['kind' => 'catalog', 'title' => 'Wholesale catalog', 'desktop_image_path' => "homepage/$client/{$catalog->id}/cover.png", 'pdf_path' => "homepage/$client/{$catalog->id}/catalog.pdf"]);

    $banner = $make('banner', 'Banners', ['layout' => 'mosaic']);
    $item($banner, ['kind' => 'content', 'desktop_image_external_url' => 'https://img.example.test/b1.jpg', 'link_url' => '/sale']);
    $item($banner, ['kind' => 'content', 'desktop_image_external_url' => 'https://img.example.test/b2.jpg']);

    $make('notice', 'Notice', []);

    return compact('config', 'refs');
}

/** Simulates another environment: no homepages, and every reference row recreated later gets a new id. */
function hpWipe(): void
{
    DB::table('homepage_section_items')->delete();
    DB::table('homepage_sections')->delete();
    DB::table('homepage_configs')->delete();
    Product::query()->delete();
    Category::query()->delete();
    Brand::query()->delete();
    foreach (Storage::disk('public')->allFiles() as $file) {
        Storage::disk('public')->delete($file);
    }
}

function hpExport(object $test, string $client = 'unity'): string
{
    $package = app(HomepageExporter::class)->export($client);
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hp-test-'.bin2hex(random_bytes(6)).'.zip';
    copy($package['path'], $path);
    unlink($package['path']);
    $test->tmp[] = $path;

    return $path;
}

/**
 * Rewrites a package. $mutate receives the raw files, the decoded manifest and the decoded homepage.json;
 * the manifest hash and checksums.json are then recomputed unless $rehash is false (to test damaged packages).
 */
function hpRepack(object $test, string $zipPath, callable $mutate, bool $rehash = true): string
{
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $files[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
    }
    $zip->close();

    $manifest = json_decode($files['manifest.json'], true);
    $content = json_decode($files['homepage.json'], true);
    $original = $files['homepage.json'];

    $mutate($files, $manifest, $content);

    if ($files['homepage.json'] === $original) {
        $files['homepage.json'] = Canonical::json($content, true);
    }

    if ($rehash) {
        $manifest['content_sha256'] = hash('sha256', $files['homepage.json']);
        $files['manifest.json'] = Canonical::json($manifest, true);
        unset($files['checksums.json']);
        $sums = [];
        foreach ($files as $name => $bytes) {
            $sums[$name] = hash('sha256', $bytes);
        }
        ksort($sums);
        $files['checksums.json'] = Canonical::json(['algorithm' => 'sha256', 'files' => $sums], true);
    } else {
        $files['manifest.json'] = Canonical::json($manifest, true);
    }

    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hp-test-'.bin2hex(random_bytes(6)).'.zip';
    $out = new ZipArchive;
    $out->open($path, ZipArchive::CREATE);
    foreach ($files as $name => $bytes) {
        $out->addFromString($name, $bytes);
    }
    $out->close();
    $test->tmp[] = $path;

    return $path;
}

function hpUpload(object $test, string $zipPath, ?User $as = null)
{
    return $test->actingAs($as ?? $test->admin)->post(HP_URL.'/validate', [
        'package' => UploadedFile::fake()->createWithContent('homepage.zip', file_get_contents($zipPath)),
    ], ['Accept' => 'application/json']);
}

function hpConfirm(object $test, string $id, string $fingerprint, bool $confirm = true, ?User $as = null)
{
    return $test->actingAs($as ?? $test->admin)->postJson(HP_URL."/imports/$id/confirm", ['base_fingerprint' => $fingerprint, 'confirm_replace' => $confirm]);
}

/** Validate then confirm in one go. Returns [validateResponse, confirmResponse]. */
function hpImport(object $test, string $zipPath): array
{
    $validated = hpUpload($test, $zipPath)->assertOk();

    return [$validated, hpConfirm($test, $validated->json('data.id'), $validated->json('data.base_fingerprint'))];
}

function hpFingerprint(string $client = 'unity'): string
{
    $snapshot = app(HomepageSnapshot::class);

    return $snapshot->fingerprint($snapshot->raw($client));
}

function hpHomepageCounts(): array
{
    return [DB::table('homepage_configs')->count(), DB::table('homepage_sections')->count(), DB::table('homepage_section_items')->count()];
}

function hpErrorCodes($response): array
{
    return array_column($response->json('data.validation.errors') ?? [], 'code');
}

// ---------------------------------------------------------------------------------------------------- export

it('exports the selected client with business keys, a valid manifest and checksums, and nothing else', function () {
    ['refs' => $refs] = hpSource();
    DB::table('users')->insert(['name' => 'Customer', 'email' => 'private.customer@example.test', 'password' => 'secret-hash', 'created_at' => now(), 'updated_at' => now()]);

    $zipPath = hpExport($this);
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    expect($names)->toContain('manifest.json', 'homepage.json', 'checksums.json', 'README.txt');
    expect(count(array_filter($names, fn ($n) => str_starts_with($n, 'assets/'))))->toBe(5); // site logo, slide, brand logo, catalog cover, catalog PDF

    $manifest = json_decode($zip->getFromName('manifest.json'), true);
    $checksums = json_decode($zip->getFromName('checksums.json'), true);
    $homepageJson = $zip->getFromName('homepage.json');

    expect($manifest)->toMatchArray(['package_type' => 'homepage', 'schema_version' => 1, 'client_key' => 'unity', 'checksum_algorithm' => 'sha256']);
    expect($manifest['includes'])->toMatchArray(['sections' => 8, 'product_references' => 4, 'category_references' => 1, 'brand_references' => 2]);
    expect($manifest['content_sha256'])->toBe(hash('sha256', $homepageJson));
    foreach ($checksums['files'] as $name => $sum) {
        expect(hash('sha256', $zip->getFromName($name)))->toBe($sum);
    }

    // Only business keys: no database ids for references, nothing outside the homepage.
    $content = json_decode($homepageJson, true);
    $carousel = $content['homepage']['sections'][1]['settings'];
    expect(array_column($carousel['product_refs'], 'erp_product_id'))->toBe(['P-3', 'P-1', 'P-2'])
        ->and($carousel)->not->toHaveKey('product_ids');
    expect($homepageJson)->not->toContain('private.customer')->not->toContain('secret-hash')->not->toContain('internal_note')->not->toContain('not part of the homepage package');
    expect($content['homepage']['site_settings'])->toHaveKeys(['site_name', 'phone'])->not->toHaveKey('internal_note');

    $zip->close();
    expect($names)->not->toContain('.env');
});

it('produces identical homepage content on every export of an unchanged homepage', function () {
    hpSource();
    $a = hpExport($this);
    $b = hpExport($this);

    $read = function (string $path) {
        $zip = new ZipArchive;
        $zip->open($path);
        $data = [$zip->getFromName('homepage.json'), json_decode($zip->getFromName('manifest.json'), true)];
        $zip->close();

        return $data;
    };
    [$jsonA, $manifestA] = $read($a);
    [$jsonB, $manifestB] = $read($b);

    expect($jsonA)->toBe($jsonB)
        ->and($manifestA['content_sha256'])->toBe($manifestB['content_sha256'])
        ->and($manifestA['export_id'])->not->toBe($manifestB['export_id']);
});

it('refuses to export a client without a homepage, an invalid client, or a homepage whose files are missing', function () {
    expect(fn () => app(HomepageExporter::class)->export('nobody'))->toThrow(HomepageTransferException::class, 'no homepage');
    expect(fn () => app(HomepageExporter::class)->export('../etc'))->toThrow(HomepageTransferException::class);

    hpSource();
    Storage::disk('public')->delete('homepage/unity/site/old-logo.png');

    try {
        app(HomepageExporter::class)->export('unity');
        $this->fail('Expected the export to refuse a homepage with a missing file.');
    } catch (HomepageTransferException $e) {
        expect($e->errorCode)->toBe('export.incomplete');
    }
});

it('serves the export download only to administrators', function () {
    $source = hpSource();
    $url = "/admin/homepage/{$source['config']->id}/export";

    $this->get($url)->assertRedirect();                                                  // guest
    $this->actingAs(User::factory()->create(['is_admin' => false]))->get($url)->assertForbidden();
    $this->actingAs($this->admin)->get($url)->assertOk()->assertHeader('content-type', 'application/zip');
});

// ---------------------------------------------------------------------------------------------------- validation + dry run

it('validates a package in another environment, maps references by business key and changes nothing', function () {
    hpSource();
    $zip = hpExport($this);
    hpWipe();
    $new = hpReferences();   // same business keys, new database ids
    expect($new['products']['P-1']->id)->toBeGreaterThan(5);

    $filesBefore = Storage::disk('public')->allFiles();
    Event::fake([HomepageImported::class]);

    $response = hpUpload($this, $zip)->assertOk();

    expect($response->json('data.status'))->toBe('validated')
        ->and($response->json('data.validation.errors'))->toBe([])
        ->and($response->json('data.validation.references.products'))->toMatchArray(['referenced' => 4, 'resolved' => 4, 'missing_count' => 0])
        ->and($response->json('data.summary'))->toMatchArray(['creates_homepage' => true, 'replaces_homepage' => true])
        ->and($response->json('data.summary.counts.sections_added'))->toBe(8)
        ->and($response->json('data.backup.available'))->toBeFalse();
    // Dry run: no homepage rows, no files written to the public disk, no import event.
    expect(hpHomepageCounts())->toBe([0, 0, 0])
        ->and(Storage::disk('public')->allFiles())->toBe($filesBefore);
    Event::assertNotDispatched(HomepageImported::class);
    expect(json_encode($response->json()))->not->toContain('backup_path')->not->toContain('upload_path');
});

it('rejects invalid packages with specific blocking errors', function (string $label, callable $mutate, string $code, bool $rehash) {
    hpSource();
    $good = hpExport($this);
    $bad = hpRepack($this, $good, $mutate, $rehash);
    $before = hpHomepageCounts();

    $response = hpUpload($this, $bad)->assertStatus(422);

    expect(hpErrorCodes($response))->toContain($code)
        ->and($response->json('data.status'))->toBe('invalid')
        ->and(hpHomepageCounts())->toBe($before)
        ->and(HomepageImport::where('status', 'validated')->count())->toBe(0);
})->with([
    'malformed json' => ['malformed json', function (&$files) {
        $files['homepage.json'] = '{not json';
    }, 'json.invalid', true],
    'missing required field' => ['missing label', function (&$f, &$m, &$c) {
        unset($c['homepage']['label']);
    }, 'content.invalid', true],
    'unsupported schema' => ['future schema', function (&$f, &$m, &$c) {
        $m['schema_version'] = 99;
        $c['schema_version'] = 99;
    }, 'manifest.schema_version', true],
    'client mismatch' => ['client mismatch', function (&$f, &$m) {
        $m['client_key'] = 'other';
    }, 'manifest.inconsistent', true],
    'damaged file' => ['checksum', function (&$files) {
        $files['README.txt'] .= ' tampered';
    }, 'checksum.invalid', false],
    'missing product' => ['missing product', function (&$f, &$m, &$c) {
        $c['homepage']['sections'][1]['settings']['product_refs'][0]['erp_product_id'] = 'NOPE';
    }, 'reference.missing', true],
    'duplicate section id' => ['duplicate ref', function (&$f, &$m, &$c) {
        $c['homepage']['sections'][1]['ref'] = $c['homepage']['sections'][0]['ref'];
    }, 'content.invalid', true],
    'unexpected entry' => ['evil.php', function (&$files) {
        $files['evil.php'] = '<?php echo 1;';
    }, 'archive.unexpected_entry', true],
    'path traversal' => ['traversal', function (&$files) {
        $files['../evil.txt'] = 'x';
    }, 'archive.unexpected_entry', true],
    'nested traversal' => ['traversal 2', function (&$files) {
        $files['assets/../../x.png'] = 'x';
    }, 'archive.unexpected_entry', true],
    'unknown field' => ['unknown field', function (&$f, &$m, &$c) {
        $c['homepage']['sections'][0]['evil'] = 1;
    }, 'content.unknown_field', true],
    'javascript link' => ['js url', function (&$f, &$m, &$c) {
        $c['homepage']['sections'][0]['items'][0]['link_url'] = 'javascript:alert(1)';
    }, 'content.unsafe_url', true],
    'asset is not an image' => ['fake image', function (&$files, &$m, &$c) {
        $files[$c['assets'][0]['file']] = 'this is not an image';
    }, 'asset.type', true],
    'asset file missing' => ['missing asset', function (&$files, &$m, &$c) {
        unset($files[$c['assets'][0]['file']]);
    }, 'asset.invalid', true],
    'section order' => ['order', function (&$f, &$m, &$c) {
        $c['homepage']['sections'][0]['sort_order'] = 5;
    }, 'content.section_order', true],
    'wrong item kind' => ['kind', function (&$f, &$m, &$c) {
        $c['homepage']['sections'][0]['items'][0]['kind'] = 'brand';
    }, 'content.item_kind', true],
    'image without source' => ['no image', function (&$f, &$m, &$c) {
        $c['homepage']['sections'][6]['items'][0]['desktop_image'] = ['asset' => null, 'external_url' => null];
    }, 'content.image_missing', true],
]);

it('refuses oversized, over-compressed and over-long archives', function () {
    hpSource();
    $good = hpExport($this);

    config(['homepage_transfer.max_json_bytes' => 100]);
    expect(hpErrorCodes(hpUpload($this, $good)->assertStatus(422)))->toContain('archive.entry_too_large');
    config(['homepage_transfer.max_json_bytes' => 5 * 1024 * 1024]);

    config(['homepage_transfer.max_entries' => 3]);
    expect(hpErrorCodes(hpUpload($this, $good)->assertStatus(422)))->toContain('archive.entry_count');
    config(['homepage_transfer.max_entries' => 400]);

    $bomb = hpRepack($this, $good, function (&$files) {
        $files['assets/'.str_repeat('a', 64).'.png'] = str_repeat("\0", 3 * 1024 * 1024);
    });
    config(['homepage_transfer.max_compression_ratio' => 20]);
    expect(hpErrorCodes(hpUpload($this, $bomb)->assertStatus(422)))->toContain('archive.compression_ratio');
    config(['homepage_transfer.max_compression_ratio' => 200]);

    config(['homepage_transfer.max_total_uncompressed_bytes' => 1000]);
    expect(hpErrorCodes(hpUpload($this, $good)->assertStatus(422)))->toContain('archive.too_large');
});

it('rejects files that are not ZIP packages', function () {
    $path = sys_get_temp_dir().'/hp-text-'.bin2hex(random_bytes(4)).'.zip';
    file_put_contents($path, 'this is just text');
    $this->tmp[] = $path;

    $this->actingAs($this->admin)->post(HP_URL.'/validate', ['package' => UploadedFile::fake()->createWithContent('x.zip', 'this is just text')], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('error.code', 'archive.unreadable');
    $this->actingAs($this->admin)->postJson(HP_URL.'/validate', [])->assertStatus(422);
});

it('only lets administrators use any homepage transfer endpoint', function () {
    hpSource();
    $zip = hpExport($this);
    $customer = User::factory()->create(['is_admin' => false]);
    $upload = fn () => ['package' => UploadedFile::fake()->createWithContent('p.zip', file_get_contents($zip))];

    // Guests are sent to log in; they can never reach a handler.
    $this->post(HP_URL.'/validate', $upload())->assertRedirect();
    $this->postJson(HP_URL.'/imports/'.fake()->uuid().'/confirm', [])->assertUnauthorized();

    // Signed-in non-administrators are refused.
    $this->actingAs($customer)->post(HP_URL.'/validate', $upload(), ['Accept' => 'application/json'])->assertForbidden();
    $existing = HomepageImport::create(['uuid' => (string) fake()->uuid(), 'client_key' => 'unity', 'status' => 'succeeded']);
    $this->actingAs($customer)->getJson(HP_URL."/imports/{$existing->uuid}")->assertForbidden();
    $this->actingAs($customer)->postJson(HP_URL."/imports/{$existing->uuid}/confirm", ['base_fingerprint' => str_repeat('a', 64), 'confirm_replace' => true])->assertForbidden();
    $this->actingAs($customer)->postJson(HP_URL."/imports/{$existing->uuid}/rollback", [])->assertForbidden();
    $this->actingAs($customer)->get(HP_URL)->assertForbidden();

    expect(HomepageImport::count())->toBe(1)->and($existing->fresh()->status)->toBe('succeeded');
});

it('rate limits the transfer endpoints', function () {
    config(['homepage_transfer.rate_limit_per_minute' => 2]);

    $this->actingAs($this->admin)->postJson(HP_URL.'/validate', [])->assertStatus(422);
    $this->actingAs($this->admin)->postJson(HP_URL.'/validate', [])->assertStatus(422);
    $this->actingAs($this->admin)->postJson(HP_URL.'/validate', [])->assertStatus(429);
});

// ---------------------------------------------------------------------------------------------------- import

it('imports a package into another environment, mapping every reference to the local ids', function () {
    hpSource();
    $zip = hpExport($this);
    hpWipe();
    $new = hpReferences();
    Event::fake([HomepageImported::class]);

    [$validated, $done] = hpImport($this, $zip);
    $done->assertOk();
    $import = HomepageImport::where('uuid', $done->json('data.id'))->first();

    expect($done->json('data.status'))->toBe('succeeded')
        ->and($done->json('data.verification.ok'))->toBeTrue()
        ->and($done->json('data.backup.available'))->toBeTrue()
        ->and($import->upload_path)->toBeNull()
        ->and($import->idempotency_key)->not->toBeNull()
        ->and($import->result_fingerprint)->toBe(hpFingerprint());
    Event::assertDispatchedTimes(HomepageImported::class, 1);

    // The new environment's ids, not the exporting environment's.
    $sections = HomepageConfig::where('client_key', 'unity')->first()->sections()->get();
    expect($sections->pluck('type')->all())->toBe(['hero', 'product_carousel', 'product_carousel', 'featured_category', 'brand_showcase', 'catalog_showcase', 'banner', 'notice']);
    expect($sections[1]->settings['product_ids'])->toBe([$new['products']['P-3']->id, $new['products']['P-1']->id, $new['products']['P-2']->id]);
    expect($sections[3]->settings['category_id'])->toBe($new['category']->id);
    expect($sections[4]->settings['brand_ids'])->toBe([$new['brands'][802]->id, $new['brands'][801]->id]);

    // Uploaded files were carried over and are served by the existing public API.
    $this->getJson('/api/homepage?client=unity')->assertOk()
        ->assertJsonPath('client', 'unity')
        ->assertJsonPath('sections.1.data.product_ids.0', $new['products']['P-3']->id)
        ->assertJsonPath('sections.0.items.0.mobile_image_url', 'https://img.example.test/m.jpg');
    $logo = HomepageConfig::where('client_key', 'unity')->first()->site_logo_path;
    expect(Storage::disk('public')->exists($logo))->toBeTrue()->and($logo)->toStartWith('homepage/unity/site/');
    $pdf = HomepageSectionItem::whereNotNull('pdf_path')->first()->pdf_path;
    expect(Storage::disk('public')->get($pdf))->toStartWith('%PDF-');
    // A verified backup was taken first (of the empty state) and is private.
    expect(Storage::disk('local')->exists($import->backup_path))->toBeTrue();
    expect(Storage::disk('public')->exists($import->backup_path))->toBeFalse();
});

it('updates matching sections in place without duplicating, and adds and removes the rest', function () {
    ['config' => $config] = hpSource();
    $first = hpExport($this);

    // The homepage evolves: a title changes, the notice goes, a new section appears.
    $config->sections()->where('type', 'hero')->first()->update(['title' => 'Welcome back']);
    $config->sections()->where('type', 'notice')->delete();
    $config->sections()->create(['type' => 'feature_list', 'title' => 'Why us', 'is_active' => true, 'sort_order' => 7, 'settings' => ['features' => [['icon' => 'truck', 'title' => 'Fast']]]])
        ->items()->create(['kind' => 'content', 'desktop_image_external_url' => 'https://img.example.test/f.jpg', 'sort_order' => 0, 'is_active' => true]);
    $second = hpExport($this);

    hpWipe();
    hpReferences();
    hpImport($this, $first)[1]->assertOk();
    $idsAfterFirst = HomepageSection::pluck('id', 'title')->all();

    $validated = hpUpload($this, $second)->assertOk();
    expect($validated->json('data.summary.counts'))->toMatchArray(['sections_added' => 1, 'sections_removed' => 1, 'sections_updated' => 1]);
    hpConfirm($this, $validated->json('data.id'), $validated->json('data.base_fingerprint'))->assertOk();

    $now = HomepageSection::pluck('id', 'title')->all();
    expect($now['Welcome back'])->toBe($idsAfterFirst['Welcome'])           // same row, updated in place
        ->and($now['Top picks'])->toBe($idsAfterFirst['Top picks'])
        ->and(array_key_exists('Notice', $now))->toBeFalse()
        ->and(array_key_exists('Why us', $now))->toBeTrue()
        ->and(HomepageSection::count())->toBe(8)
        ->and(HomepageConfig::where('client_key', 'unity')->count())->toBe(1);
});

it('does not apply the same package twice', function () {
    hpSource();
    $zip = hpExport($this);
    hpWipe();
    hpReferences();

    hpImport($this, $zip)[1]->assertOk();
    $counts = hpHomepageCounts();

    $again = hpUpload($this, $zip)->assertStatus(422);
    expect(hpErrorCodes($again))->toContain('import.already_applied')
        ->and(hpHomepageCounts())->toBe($counts);
});

it('blocks a second confirmation of the same package validated twice, and enforces uniqueness in the database', function () {
    hpSource();
    $zip = hpExport($this);
    hpWipe();
    hpReferences();

    $a = hpUpload($this, $zip)->assertOk();
    $b = hpUpload($this, $zip)->assertOk();                       // both validated before either is applied
    hpConfirm($this, $a->json('data.id'), $a->json('data.base_fingerprint'))->assertOk();
    $counts = hpHomepageCounts();

    hpConfirm($this, $b->json('data.id'), $b->json('data.base_fingerprint'))->assertStatus(409);
    expect(hpHomepageCounts())->toBe($counts);

    $key = HomepageImport::where('uuid', $a->json('data.id'))->value('idempotency_key');
    expect(fn () => HomepageImport::create(['uuid' => (string) fake()->uuid(), 'client_key' => 'unity', 'status' => 'succeeded', 'idempotency_key' => $key]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refuses to import over a homepage that changed after the preview', function () {
    hpSource();
    $zip = hpExport($this);
    hpWipe();
    hpReferences();

    $validated = hpUpload($this, $zip)->assertOk();
    HomepageConfig::create(['client_key' => 'unity', 'label' => 'Someone created this meanwhile', 'is_active' => true]);
    $before = hpFingerprint();

    hpConfirm($this, $validated->json('data.id'), $validated->json('data.base_fingerprint'))->assertStatus(409)->assertJsonPath('error.code', 'import.stale');

    expect(hpFingerprint())->toBe($before)
        ->and(HomepageSection::count())->toBe(0)
        ->and(HomepageImport::where('uuid', $validated->json('data.id'))->value('status'))->toBe('expired');
});

it('requires an explicit confirmation bound to the validated preview', function () {
    hpSource();
    $zip = hpExport($this);
    hpWipe();
    hpReferences();
    $validated = hpUpload($this, $zip)->assertOk();
    $id = $validated->json('data.id');

    hpConfirm($this, $id, $validated->json('data.base_fingerprint'), confirm: false)->assertStatus(422);
    hpConfirm($this, $id, str_repeat('a', 64))->assertStatus(409)->assertJsonPath('error.code', 'import.stale');
    $this->actingAs($this->admin)->postJson(HP_URL."/imports/$id/confirm", [])->assertStatus(422);
    // Another administrator cannot confirm someone else's validation.
    hpConfirm($this, $id, $validated->json('data.base_fingerprint'), as: User::factory()->create(['is_admin' => true]))->assertForbidden();

    expect(hpHomepageCounts())->toBe([0, 0, 0])
        ->and(HomepageImport::where('uuid', $id)->value('status'))->toBe('validated');
});

it('expires a validation that is not confirmed in time', function () {
    hpSource();
    $zip = hpExport($this);
    hpWipe();
    hpReferences();
    $validated = hpUpload($this, $zip)->assertOk();

    $this->travel(2)->hours();
    hpConfirm($this, $validated->json('data.id'), $validated->json('data.base_fingerprint'))->assertStatus(409)->assertJsonPath('error.code', 'import.expired');

    expect(HomepageImport::where('uuid', $validated->json('data.id'))->first())->status->toBe('expired')->upload_path->toBeNull()
        ->and(hpHomepageCounts())->toBe([0, 0, 0]);
});

it('preserves other clients and settings outside the homepage allowlist', function () {
    hpSource();
    $zip = hpExport($this);

    // The target already has a unity homepage with an extra setting that is not part of the package, plus another client.
    HomepageConfig::where('client_key', 'unity')->first()->update(['site_settings' => ['site_name' => 'Old name', 'internal_note' => 'keep me']]);
    $other = HomepageConfig::create(['client_key' => 'other-client', 'label' => 'Other', 'is_active' => true, 'site_settings' => ['site_name' => 'Other']]);
    $other->sections()->create(['type' => 'notice', 'title' => 'Other notice', 'is_active' => true, 'sort_order' => 0, 'settings' => []]);
    $otherBefore = hpFingerprint('other-client');

    hpImport($this, $zip)[1]->assertOk();

    $settings = HomepageConfig::where('client_key', 'unity')->first()->site_settings;
    expect($settings['site_name'])->toBe('Unity Wholesale')
        ->and($settings['internal_note'])->toBe('keep me')
        ->and(hpFingerprint('other-client'))->toBe($otherBefore);
});

it('treats a missing product in a list the section does not use as a warning, not a blocker', function () {
    hpSource();
    $zip = hpRepack($this, hpExport($this), function (&$f, &$m, &$c) {
        $c['homepage']['sections'][2]['settings']['product_refs'] = [['erp_product_id' => 'GONE', 'name' => 'Gone']];   // source = newest
    });

    $response = hpUpload($this, $zip)->assertOk();

    expect(array_column($response->json('data.validation.warnings'), 'code'))->toContain('reference.missing');
});

it('keeps the live homepage when the database fails during the import', function () {
    hpSource();
    $zip = hpExport($this);
    hpWipe();
    hpReferences();
    Event::fake([HomepageImported::class]);

    $validated = hpUpload($this, $zip)->assertOk();
    $before = hpFingerprint();
    $items = 0;
    HomepageSectionItem::creating(function () use (&$items) {
        if (++$items === 4) {
            throw new RuntimeException('simulated database failure');
        }
    });

    $response = hpConfirm($this, $validated->json('data.id'), $validated->json('data.base_fingerprint'))->assertStatus(500);
    $import = HomepageImport::where('uuid', $validated->json('data.id'))->first();

    expect(json_encode($response->json()))->not->toContain('simulated')->not->toContain('vendor')
        ->and($import->status)->toBe('failed')
        ->and(hpHomepageCounts())->toBe([0, 0, 0])             // no partial homepage
        ->and(hpFingerprint())->toBe($before)
        ->and(Storage::disk('public')->allFiles())->toBe([])   // files written during the attempt were removed
        ->and(Storage::disk('local')->exists($import->backup_path))->toBeTrue()
        ->and($import->idempotency_key)->toBeNull();
    Event::assertNotDispatched(HomepageImported::class);

    // A failed import is not a completed one: the same package can be validated and applied afterwards.
    HomepageSectionItem::flushEventListeners();
    hpImport($this, $zip)[1]->assertOk();
    expect(HomepageSection::count())->toBe(8);
});

it('does not touch the homepage when the backup cannot be made', function () {
    hpSource();
    $zip = hpExport($this);
    hpWipe();
    hpReferences();
    $validated = hpUpload($this, $zip)->assertOk();

    $this->instance(HomepageBackup::class, Mockery::mock(HomepageBackup::class, fn ($mock) => $mock->shouldReceive('create')->andThrow(new HomepageTransferException('backup.write', 'The backup could not be written, so nothing was changed.', 500))));

    hpConfirm($this, $validated->json('data.id'), $validated->json('data.base_fingerprint'))->assertStatus(500)->assertJsonPath('error.code', 'backup.write');

    expect(hpHomepageCounts())->toBe([0, 0, 0])
        ->and(HomepageImport::where('uuid', $validated->json('data.id'))->first())->status->toBe('failed')->error_category->toBe('backup');
});

it('restores the previous homepage automatically when the public API check fails after the commit', function () {
    hpSource();
    $zip = hpExport($this);
    hpWipe();
    hpReferences();
    Event::fake([HomepageImported::class]);
    $this->instance(HomepagePostImportVerifier::class, new class extends HomepagePostImportVerifier
    {
        public function verify(string $clientKey, ?array $intended): array
        {
            return ['ok' => false, 'checks' => [['name' => 'public API status', 'ok' => false, 'detail' => 'simulated']]];
        }
    });

    [$validated, $done] = hpImport($this, $zip);

    $import = HomepageImport::where('uuid', $validated->json('data.id'))->first();
    expect($done->status())->toBe(409)
        ->and($import->status)->toBe('rolled_back')
        ->and($import->error_category)->toBe('verification_failed')
        ->and($import->idempotency_key)->toBeNull()
        ->and(hpHomepageCounts())->toBe([0, 0, 0]);
    Event::assertNotDispatched(HomepageImported::class);
});

it('flags recovery_required when the automatic restore also fails', function () {
    hpSource();
    $zip = hpExport($this);
    hpWipe();
    hpReferences();
    $this->instance(HomepagePostImportVerifier::class, new class extends HomepagePostImportVerifier
    {
        public function verify(string $clientKey, ?array $intended): array
        {
            // Sabotage the backup so the automatic restore cannot trust it.
            foreach (Storage::disk('local')->allFiles('homepage-backups') as $file) {
                Storage::disk('local')->put($file, 'damaged');
            }

            return ['ok' => false, 'checks' => []];
        }
    });

    [$validated] = hpImport($this, $zip);

    expect(HomepageImport::where('uuid', $validated->json('data.id'))->value('status'))->toBe('recovery_required');
});

it('refuses to run two operations on one client at once', function () {
    hpSource();
    $zip = hpExport($this);
    hpWipe();
    hpReferences();
    $validated = hpUpload($this, $zip)->assertOk();

    $lock = Cache::lock('homepage-transfer:unity', 60);
    expect($lock->get())->toBeTrue();
    hpConfirm($this, $validated->json('data.id'), $validated->json('data.base_fingerprint'))->assertStatus(409)->assertJsonPath('error.code', 'transfer.busy');
    $lock->release();

    expect(hpHomepageCounts())->toBe([0, 0, 0])
        ->and(HomepageImport::where('uuid', $validated->json('data.id'))->value('status'))->toBe('validated');
    hpConfirm($this, $validated->json('data.id'), $validated->json('data.base_fingerprint'))->assertOk();
});

it('marks an operation that died mid-way as interrupted and carries on', function () {
    hpSource();
    $zip = hpExport($this);
    hpWipe();
    hpReferences();
    $stuck = HomepageImport::create(['uuid' => (string) fake()->uuid(), 'client_key' => 'unity', 'kind' => 'import', 'status' => 'in_progress', 'started_at' => now()->subHour()]);
    $recent = HomepageImport::create(['uuid' => (string) fake()->uuid(), 'client_key' => 'unity', 'kind' => 'import', 'status' => 'in_progress', 'started_at' => now()]);

    expect($stuck->isInterrupted())->toBeTrue()->and($recent->isInterrupted())->toBeFalse();
    $validated = hpUpload($this, $zip)->assertOk();

    // A recent in-progress operation still blocks...
    hpConfirm($this, $validated->json('data.id'), $validated->json('data.base_fingerprint'))->assertStatus(409)->assertJsonPath('error.code', 'import.busy');
    $recent->update(['started_at' => now()->subHour()]);
    // ...but a dead one does not, and is recorded as such.
    hpConfirm($this, $validated->json('data.id'), $validated->json('data.base_fingerprint'))->assertOk();
    expect($stuck->fresh()->status)->toBe('failed')->and($stuck->fresh()->error_category)->toBe('interrupted');
});

it('only allows the documented status transitions', function () {
    $import = HomepageImport::create(['uuid' => (string) fake()->uuid(), 'client_key' => 'unity', 'status' => 'validated']);

    expect(fn () => $import->transitionTo('succeeded'))->toThrow(LogicException::class);
    $import->transitionTo('in_progress');
    $import->transitionTo('failed');
    expect(fn () => $import->transitionTo('in_progress'))->toThrow(LogicException::class);
});

// ---------------------------------------------------------------------------------------------------- rollback

/** Builds: environment with an older homepage imported, then a newer package imported over it. */
function hpTwoImports(object $test): array
{
    ['config' => $config] = hpSource();
    $first = hpExport($test);
    $config->sections()->where('type', 'hero')->first()->update(['title' => 'Version two']);
    $config->sections()->where('type', 'notice')->delete();
    $second = hpExport($test);

    hpWipe();
    hpReferences();
    hpImport($test, $first)[1]->assertOk();
    $afterFirst = hpFingerprint();
    $filesAfterFirst = Storage::disk('public')->allFiles();
    [$validated, $done] = hpImport($test, $second);
    $done->assertOk();

    return [HomepageImport::where('uuid', $done->json('data.id'))->first(), $afterFirst, $filesAfterFirst];
}

it('rolls back to the verified backup, keeps files, and records everything', function () {
    [$import, $afterFirst, $filesBefore] = hpTwoImports($this);
    expect(hpFingerprint())->not->toBe($afterFirst);
    Event::fake([HomepageRestored::class]);

    $response = $this->actingAs($this->admin)->postJson(HP_URL."/imports/{$import->uuid}/rollback", [])->assertOk();

    expect($response->json('data.kind'))->toBe('rollback')
        ->and($response->json('data.status'))->toBe('succeeded')
        ->and($response->json('data.source_import_id'))->toBe($import->uuid)
        ->and($response->json('data.verification.ok'))->toBeTrue()
        ->and($response->json('data.backup.available'))->toBeTrue()             // rolling back is itself reversible
        ->and(hpFingerprint())->toBe($afterFirst)
        ->and($import->fresh())->status->toBe('rolled_back')->rolled_back_at->not->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toEqualCanonicalizing(array_values(array_unique(array_merge($filesBefore, Storage::disk('public')->allFiles()))));
    Event::assertDispatchedTimes(HomepageRestored::class, 1);
    $this->getJson('/api/homepage?client=unity')->assertOk()->assertJsonPath('sections.0.title', 'Welcome');

    // A rolled-back operation cannot be rolled back again.
    $this->actingAs($this->admin)->postJson(HP_URL."/imports/{$import->uuid}/rollback", [])->assertStatus(409)->assertJsonPath('error.code', 'rollback.unavailable');

    // ...but the rollback itself can be undone.
    $undo = HomepageImport::where('uuid', $response->json('data.id'))->first();
    $this->actingAs($this->admin)->postJson(HP_URL."/imports/{$undo->uuid}/rollback", [])->assertOk();
    expect(HomepageSection::where('title', 'Version two')->exists())->toBeTrue();
});

it('removes a homepage that the import created when that import is rolled back', function () {
    hpSource();
    $zip = hpExport($this);
    hpWipe();
    hpReferences();
    [$validated] = hpImport($this, $zip);
    expect(hpHomepageCounts()[0])->toBe(1);

    $this->actingAs($this->admin)->postJson(HP_URL.'/imports/'.$validated->json('data.id').'/rollback', [])->assertOk();

    expect(hpHomepageCounts())->toBe([0, 0, 0]);
    $this->getJson('/api/homepage?client=unity')->assertNotFound();
});

it('will not roll back over later edits without an explicit acknowledgement', function () {
    [$import] = hpTwoImports($this);
    HomepageSection::where('title', 'Version two')->update(['title' => 'Edited by an admin afterwards']);

    $this->actingAs($this->admin)->postJson(HP_URL."/imports/{$import->uuid}/rollback", [])->assertStatus(409)->assertJsonPath('error.code', 'rollback.changed_since');
    expect(HomepageSection::where('title', 'Edited by an admin afterwards')->exists())->toBeTrue();

    $this->actingAs($this->admin)->postJson(HP_URL."/imports/{$import->uuid}/rollback", ['acknowledge_overwrite' => true])->assertOk();
    expect(HomepageSection::where('title', 'Edited by an admin afterwards')->exists())->toBeFalse();
});

it('refuses to restore a backup that was altered or belongs to another client', function () {
    [$import] = hpTwoImports($this);
    $before = hpFingerprint();

    // Cross-client: the backup reader only accepts files under the same client's directory.
    expect(fn () => app(HomepageBackup::class)->read($import->backup_path, $import->backup_sha256, 'other-client'))
        ->toThrow(HomepageTransferException::class);
    expect(fn () => app(HomepageBackup::class)->read('../../.env', str_repeat('a', 64), 'unity'))->toThrow(HomepageTransferException::class);
    expect(fn () => app(HomepageBackup::class)->create('../escape', ['exists' => false, 'client_key' => '../escape']))->toThrow(HomepageTransferException::class);

    Storage::disk('local')->put($import->backup_path, Storage::disk('local')->get($import->backup_path).' ');
    $this->actingAs($this->admin)->postJson(HP_URL."/imports/{$import->uuid}/rollback", [])->assertStatus(409)->assertJsonPath('error.code', 'backup.checksum');

    expect(hpFingerprint())->toBe($before)
        ->and($import->fresh()->status)->toBe('succeeded');
});

it('does not roll back an operation that never changed anything', function () {
    hpSource();
    $zip = hpExport($this);
    hpWipe();
    hpReferences();
    $validated = hpUpload($this, $zip)->assertOk();

    $this->actingAs($this->admin)->postJson(HP_URL.'/imports/'.$validated->json('data.id').'/rollback', [])->assertStatus(409)->assertJsonPath('error.code', 'rollback.unavailable');
});

// ---------------------------------------------------------------------------------------------------- command

it('exports from the command line without changing anything', function () {
    hpSource();
    $target = sys_get_temp_dir().'/hp-cli-'.bin2hex(random_bytes(4)).'.zip';
    $this->tmp[] = $target;
    $before = hpFingerprint();

    $this->artisan('homepage:export', ['client' => 'unity', '--output' => $target])->assertSuccessful();
    expect(is_file($target))->toBeTrue()->and(hpFingerprint())->toBe($before);

    $this->artisan('homepage:export', ['client' => 'unity', '--output' => $target])->assertFailed();          // never overwrites
    $this->artisan('homepage:export nobody')->assertFailed();
});

it('exports a file whose name does not match its content under the extension of its real type', function () {
    hpSource();
    // A PNG that was uploaded under a .jpg name: the package must carry it as a PNG, or it would fail its own validation.
    $path = HomepageSectionItem::whereNotNull('desktop_image_path')->first()->desktop_image_path;
    $renamed = str_replace('.png', '.jpg', $path);
    Storage::disk('public')->move($path, $renamed);
    HomepageSectionItem::where('desktop_image_path', $path)->update(['desktop_image_path' => $renamed]);

    $zip = hpExport($this);
    $archive = new ZipArchive;
    $archive->open($zip);
    $assets = [];
    for ($i = 0; $i < $archive->numFiles; $i++) {
        if (str_starts_with($archive->getNameIndex($i), 'assets/')) {
            $assets[] = $archive->getNameIndex($i);
        }
    }
    $archive->close();
    hpWipe();
    hpReferences();

    expect(array_filter($assets, fn ($n) => str_ends_with($n, '.jpg')))->toBe([]);
    hpUpload($this, $zip)->assertOk()->assertJsonPath('data.validation.errors', []);
});
