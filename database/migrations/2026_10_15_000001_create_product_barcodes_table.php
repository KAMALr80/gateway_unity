<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every other code a product is sold under, besides products.sku: the ERP's sku_1, sku_2 and barcode_number,
     * and for a variation its sub_sku, barcode, barcode2 and case_barcode2. The product sync replaces a product's
     * rows each time it sends that product. A code is unique within a product only; two products may share one,
     * and a scan then lists both.
     */
    public function up(): void
    {
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('code', 191);
            // The ERP column it came from, e.g. "barcode_number" or "case_barcode2".
            $table->string('source', 32)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'code']);
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
    }
};
