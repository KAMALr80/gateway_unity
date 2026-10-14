<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The storefront's "Best Sellers" page: products with a rank (1 = first), managed in the admin. A manual rank,
 * because the sales history lives in the old shop, not here. The ERP product sync never writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('best_seller_rank')->nullable()->after('is_featured')->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['best_seller_rank']);
            $table->dropColumn('best_seller_rank');
        });
    }
};
