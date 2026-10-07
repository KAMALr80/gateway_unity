<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Limits what one user sees of the catalog to a single category and its
 * sub-categories (e.g. an App Store review account). Null for everyone else.
 * Set by an admin, never by the user: it isn't in User::$fillable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('catalog_category_id')->nullable()->index()->after('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('catalog_category_id');
        });
    }
};
