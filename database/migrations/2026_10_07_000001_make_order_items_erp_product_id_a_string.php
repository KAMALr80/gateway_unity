<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * order_items.erp_product_id snapshots products.erp_product_id, which is a
 * string: ERP ids may carry a prefix ("GROUP#123", "VAR#21655"). As an integer
 * column, every order containing such a product failed to save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('erp_product_id', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Fails while any order_items row holds a non-numeric id.
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('erp_product_id')->nullable()->change();
        });
    }
};
