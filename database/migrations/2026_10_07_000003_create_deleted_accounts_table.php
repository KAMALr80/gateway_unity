<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emails of accounts their owners deleted, so the ERP's contact sync does not
 * recreate them. Only a hash is kept, not the address itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deleted_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('email_hash', 64)->unique();
            $table->timestamp('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_accounts');
    }
};
