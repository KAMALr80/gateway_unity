<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products hidden from a catalog-limited user on top of their category limit
 * (users.catalog_category_id), with their variations, e.g. items an App Store
 * reviewer shouldn't see. Set by an admin; not fillable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('catalog_hidden_product_ids')->nullable()->after('catalog_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('catalog_hidden_product_ids');
        });
    }
};
