<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ERP's own statuses for an order, as the ERP has them, next to the
 * gateway's simplified status/payment_status: the app decides what to show.
 * Null until the order is in the ERP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('erp_status', 20)->nullable()->after('payment_status');           // draft | final | quotation
            $table->string('erp_shipping_status', 20)->nullable()->after('erp_status');      // ordered | packed | shipped | delivered | cancelled
            $table->string('erp_payment_status', 20)->nullable()->after('erp_shipping_status'); // paid | partial | due
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['erp_status', 'erp_shipping_status', 'erp_payment_status']);
        });
    }
};
