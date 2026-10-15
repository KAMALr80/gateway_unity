<?php

use App\Models\Product;
use App\Models\ProductBarcode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function syncBarcodeProducts($test, array $products)
{
    config(['services.erp.internal_key' => 'test-key']);

    return $test->withHeader('X-Internal-Key', 'test-key')->postJson('/internal/products/sync', ['products' => $products]);
}

function barcodesOf(string $erpId): array
{
    return Product::where('erp_product_id', $erpId)->firstOrFail()->barcodes()->pluck('code')->all();
}

it('stores a product\'s codes cleaned: trimmed, never blank, once each and never its own sku', function () {
    syncBarcodeProducts($this, [[
        'erp_product_id' => '10', 'name' => 'Ice caps', 'sku' => 'RD ICE CAPS 12', 'regular_price' => 4,
        'barcodes' => [
            ['code' => '  094922125507 ', 'source' => 'barcode_number'],
            ['code' => 'rd ice caps 12', 'source' => 'sub_sku'],   // the sku again, in other case
            ['code' => '', 'source' => 'sku_1'],
            ['code' => '   ', 'source' => 'sku_2'],
            ['code' => null, 'source' => 'barcode'],
            ['code' => '094922125507', 'source' => 'barcode2'],    // duplicate after trimming
            ['code' => "CASE\u{00A0}  12 ", 'source' => 'case_barcode2'],
            '  7788 ',
        ],
    ]])->assertOk()->assertJsonPath('errors', []);

    $rows = Product::where('erp_product_id', '10')->first()->barcodes()->get(['code', 'source'])->toArray();

    expect($rows)->toBe([
        ['code' => '094922125507', 'source' => 'barcode_number'],
        ['code' => 'CASE 12', 'source' => 'case_barcode2'],
        ['code' => '7788', 'source' => null],
    ]);
});

it('replaces the codes on every sync and keeps them when the ERP sends none', function () {
    syncBarcodeProducts($this, [['erp_product_id' => '11', 'name' => 'Wraps', 'regular_price' => 2, 'barcodes' => ['111', '222']]]);
    expect(barcodesOf('11'))->toBe(['111', '222']);

    syncBarcodeProducts($this, [['erp_product_id' => '11', 'name' => 'Wraps', 'regular_price' => 2, 'barcodes' => ['333']]]);
    expect(barcodesOf('11'))->toBe(['333']);

    // An older ERP module doesn't send the key: nothing changes.
    syncBarcodeProducts($this, [['erp_product_id' => '11', 'name' => 'Wraps', 'regular_price' => 2]]);
    expect(barcodesOf('11'))->toBe(['333']);

    // An empty list clears them.
    syncBarcodeProducts($this, [['erp_product_id' => '11', 'name' => 'Wraps', 'regular_price' => 2, 'barcodes' => []]]);
    expect(barcodesOf('11'))->toBe([]);
});

it('lets two products share a code', function () {
    syncBarcodeProducts($this, [
        ['erp_product_id' => '12', 'name' => 'Lighter red', 'regular_price' => 1, 'barcodes' => ['555000']],
        ['erp_product_id' => '13', 'name' => 'Lighter blue', 'regular_price' => 1, 'barcodes' => ['555000']],
    ])->assertOk()->assertJsonPath('errors', []);

    $this->getJson('/api/products?barcode=555000&include_children=true')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('never saves a blank code, whoever writes it', function () {
    $product = Product::create(['name' => 'Bag', 'regular_price' => 1, 'is_active' => true, 'in_stock' => true]);

    expect(ProductBarcode::create(['product_id' => $product->id, 'code' => '   '])->exists)->toBeFalse()
        ->and(ProductBarcode::create(['product_id' => $product->id, 'code' => ' 42 '])->code)->toBe('42')
        ->and(ProductBarcode::count())->toBe(1);
});

it('finds a product by barcode with one leading 0 added or removed', function () {
    syncBarcodeProducts($this, [
        ['erp_product_id' => '20', 'name' => 'Stored as UPC-A', 'regular_price' => 1, 'barcodes' => ['077170101875']],
        ['erp_product_id' => '21', 'name' => 'Stored as EAN-13', 'regular_price' => 1, 'barcodes' => ['0040232355707']],
    ]);

    // The scanner added a 0 / the scanner dropped the stored 0 / exact.
    foreach (['0077170101875' => 'Stored as UPC-A', '040232355707' => 'Stored as EAN-13', '077170101875' => 'Stored as UPC-A'] as $scanned => $name) {
        $this->getJson("/api/products?barcode={$scanned}&include_children=true")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', $name);
    }

    // Letters are compared as they are: no 0 games with SKUs.
    Product::create(['name' => 'Lettered', 'sku' => 'FL99', 'regular_price' => 1, 'is_active' => true, 'in_stock' => true]);
    $this->getJson('/api/products?barcode=0FL99&include_children=true')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/products?barcode=FL99&include_children=true')->assertOk()->assertJsonCount(1, 'data');
});

it('lists the group of a variation found by barcode, and the variation itself with children included', function () {
    syncBarcodeProducts($this, [
        ['erp_product_id' => 'GROUP#30', 'name' => 'Cones', 'type' => 'grouped', 'regular_price' => 3, 'barcodes' => ['CONES']],
        ['erp_product_id' => 'VAR#301', 'parent_erp_id' => 'GROUP#30', 'name' => 'Cones - Mint', 'type' => 'variable', 'sku' => 'CONE-MINT', 'regular_price' => 3, 'barcodes' => ['86000301']],
    ]);

    $this->getJson('/api/products?barcode=86000301')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Cones')
        ->assertJsonPath('data.0.barcodes', ['CONES']);

    $this->getJson('/api/products?barcode=86000301&include_children=true')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Cones - Mint')
        ->assertJsonPath('data.0.sku', 'CONE-MINT')
        ->assertJsonPath('data.0.barcodes', ['86000301']);

    // The group's own ERP sku is one of its barcodes; ?barcode= also matches skus.
    $this->getJson('/api/products?barcode=CONES')->assertOk()->assertJsonPath('data.0.name', 'Cones');
    $this->getJson('/api/products?barcode=CONE-MINT&include_children=true')->assertOk()->assertJsonPath('data.0.name', 'Cones - Mint');
});

it('finds barcodes through the search box too', function () {
    syncBarcodeProducts($this, [['erp_product_id' => '40', 'name' => 'Rolling papers', 'regular_price' => 1, 'barcodes' => ['716165177554']]]);

    $this->getJson('/api/products?search=716165177554')->assertOk()->assertJsonPath('data.0.name', 'Rolling papers');
    $this->getJson('/api/products?search=0716165177554')->assertOk()->assertJsonPath('data.0.name', 'Rolling papers');
    $this->getJson('/api/products?search=papers')->assertOk()->assertJsonPath('data.0.barcodes', ['716165177554']);
});

it('rejects a barcode filter that is not a string', function () {
    $this->getJson('/api/products?barcode[]=1')->assertStatus(422);
});
