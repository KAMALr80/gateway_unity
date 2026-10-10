<?php

use App\Models\Media;
use App\Models\Product;
use App\Services\MediaThumbnails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const ERP_IMAGE = 'https://erp.centralsmokedistro.com/uploads/img/source.png';

function variantProduct(string $url = ERP_IMAGE): Product
{
    $product = Product::create(['name' => 'Pictured', 'sku' => 'PIC-'.Str::random(6), 'regular_price' => '5.00', 'is_active' => true, 'in_stock' => true]);
    Media::create([
        'mediable_type' => Product::class, 'mediable_id' => $product->id,
        'url' => $url, 'disk' => 'external', 'path' => $url, 'is_primary' => true, 'sort_order' => 0,
    ]);

    return $product;
}

function pngBytes(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));
    ob_start();
    imagepng($image);

    return ob_get_clean();
}

function variantPath(Product $product, string $size): string
{
    return parse_url(test()->getJson("/api/products/{$product->id}")->json("image_variants.{$size}"), PHP_URL_PATH);
}

beforeEach(function () {
    Storage::fake('local');
    config(['media.thumbnails.source_hosts' => ['erp.centralsmokedistro.com']]);
});

test('product API keeps the original image and adds variant URLs', function () {
    $product = variantProduct();

    $detail = $this->getJson("/api/products/{$product->id}")->assertOk();
    expect($detail->json('image'))->toBe(ERP_IMAGE)
        ->and($detail->json('image_variants.original'))->toBe(ERP_IMAGE)
        ->and(array_keys($detail->json('image_variants')))->toBe([64, 256, 512, 1024, 'original'])
        ->and($detail->json('image_variants.256'))->toMatch('#/api/media/\d+/[a-f0-9]{20}/256\.webp$#')
        ->and($detail->json('images.0.url'))->toBe(ERP_IMAGE)
        ->and($detail->json('images.0.variants.64'))->toBe($detail->json('image_variants.64'));

    $list = $this->getJson('/api/products')->assertOk();
    expect($list->json('data.0.image'))->toBe(ERP_IMAGE)
        ->and($list->json('data.0.image_variants.512'))->toBe($detail->json('image_variants.512'));
});

test('images on hosts outside the allow-list, and products without images, get no variants', function () {
    $other = variantProduct('https://example.com/a.png');
    $plain = Product::create(['name' => 'Plain', 'sku' => 'PLAIN-1', 'regular_price' => '1.00', 'is_active' => true, 'in_stock' => true]);

    expect($this->getJson("/api/products/{$other->id}")->json('image_variants'))->toBeNull()
        ->and($this->getJson("/api/products/{$other->id}")->json('image'))->toBe('https://example.com/a.png')
        ->and($this->getJson("/api/products/{$plain->id}")->json('image_variants'))->toBeNull();
});

test('a variant is generated once as WebP, fits the size and is never upscaled', function () {
    Http::fake([ERP_IMAGE => Http::response(pngBytes(800, 600), 200, ['Content-Type' => 'image/png'])]);
    $product = variantProduct();

    foreach (['64' => [64, 48], '512' => [512, 384], '1024' => [800, 600]] as $size => [$w, $h]) {
        $response = $this->get(variantPath($product, $size))->assertOk();
        $info = getimagesizefromstring($response->streamedContent());
        expect($response->headers->get('Content-Type'))->toBe('image/webp')
            ->and($response->headers->get('Cache-Control'))->toContain('max-age=31536000')
            ->and($response->headers->get('Cache-Control'))->toContain('immutable')
            ->and($response->headers->get('Cache-Control'))->toContain('public')
            ->and([$info[0], $info[1], $info['mime']])->toBe([$w, $h, 'image/webp']);
    }

    Http::assertSentCount(1);
});

test('variant falls back to the original when the source cannot be used', function (callable $source) {
    Http::fake([ERP_IMAGE => $source()]);
    $product = variantProduct();

    $this->get(variantPath($product, '256'))->assertRedirect(ERP_IMAGE);
    // the failure is remembered: no second download attempt
    $this->get(variantPath($product, '256'))->assertRedirect(ERP_IMAGE);
    Http::assertSentCount(1);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'server error' => fn () => fn () => Http::response('down', 500),
    'not an image' => fn () => fn () => Http::response('<html>', 200, ['Content-Type' => 'text/html']),
    'image type but not decodable' => fn () => fn () => Http::response('not really a png', 200, ['Content-Type' => 'image/png']),
    'too large' => fn () => fn () => Http::response('x', 200, ['Content-Type' => 'image/png', 'Content-Length' => (string) (20 * 1024 * 1024)]),
    'redirect elsewhere' => fn () => fn () => Http::response('', 302, ['Location' => 'http://169.254.169.254/']),
]);

test('variant URLs reject unknown sizes, wrong hashes, unknown media and ineligible hosts', function () {
    Http::fake();
    $product = variantProduct();
    $path = variantPath($product, '256');

    $this->get(str_replace('/256.webp', '/300.webp', $path))->assertNotFound();
    $this->get(preg_replace('#/[a-f0-9]{20}/#', '/'.str_repeat('0', 20).'/', $path))->assertNotFound();
    $this->get(preg_replace('#/api/media/\d+/#', '/api/media/999999/', $path))->assertNotFound();

    $media = Media::firstOrFail();
    $media->update(['url' => 'https://example.com/a.png', 'path' => 'https://example.com/a.png']);
    $key = app(MediaThumbnails::class)->key('https://example.com/a.png');
    $this->get("/api/media/{$media->id}/{$key}/256.webp")->assertNotFound();

    Http::assertNothingSent();
});

test('only https sources on the allow-listed host are eligible', function () {
    $thumbnails = app(MediaThumbnails::class);

    expect($thumbnails->eligible(ERP_IMAGE))->toBeTrue()
        ->and($thumbnails->eligible('http://erp.centralsmokedistro.com/uploads/img/a.png'))->toBeFalse()
        ->and($thumbnails->eligible('https://erp.centralsmokedistro.com:8443/a.png'))->toBeFalse()
        ->and($thumbnails->eligible('https://user:pw@erp.centralsmokedistro.com/a.png'))->toBeFalse()
        ->and($thumbnails->eligible('https://erp.centralsmokedistro.com.evil.test/a.png'))->toBeFalse()
        ->and($thumbnails->eligible('https://127.0.0.1/a.png'))->toBeFalse()
        ->and($thumbnails->eligible(null))->toBeFalse();

    config(['media.thumbnails.enabled' => false]);
    expect($thumbnails->eligible(ERP_IMAGE))->toBeFalse();
});

test('warm command pre-generates variants for primary product images', function () {
    Http::fake([ERP_IMAGE => Http::response(pngBytes(400, 400), 200, ['Content-Type' => 'image/png'])]);
    variantProduct();
    variantProduct('https://example.com/skip.png');

    $this->artisan('media:warm-variants')->expectsOutputToContain('Variants ready: 1, failed: 0, skipped (not eligible): 1')->assertSuccessful();
    expect(Storage::disk('local')->allFiles())->toHaveCount(4);
});
