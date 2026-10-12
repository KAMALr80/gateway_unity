<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERP customers sign in with their WooCommerce (unitywholesales.com) login, sent by the ERP's
 * contact sync: the contact code (contacts.contact_id, e.g. "CO0123"), the Woo username and the
 * Woo password. The password is kept encrypted so "forgot password" can email it, and is the
 * only password such an account accepts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('erp_contact_code', 191)->nullable()->after('erp_contact_id')->index();
            $table->string('woo_username', 191)->nullable()->after('erp_contact_code')->index();
            $table->text('woo_password')->nullable()->after('woo_username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['erp_contact_code']);
            $table->dropIndex(['woo_username']);
            $table->dropColumn(['erp_contact_code', 'woo_username', 'woo_password']);
        });
    }
};
