<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Audit trail, validation sessions, idempotency and backup metadata for homepage import/export.
 * Additive only: it touches no existing table. The backups themselves are private files; this table
 * records where they are and their checksums.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('kind', 16)->default('import');          // import | rollback
            $table->string('client_key', 64)->index();
            $table->string('status', 24)->index();                   // see App\Models\HomepageImport::TRANSITIONS
            $table->string('package_id', 64)->nullable();            // manifest export_id
            $table->char('package_sha256', 64)->nullable();
            $table->unsignedSmallInteger('schema_version')->nullable();
            $table->unsignedBigInteger('admin_user_id')->nullable()->index();
            // Set only while status = succeeded, and unique, so one package can be applied to one client once
            // even when two requests race (NULLs never collide).
            $table->char('idempotency_key', 64)->nullable()->unique();
            $table->char('base_fingerprint', 64)->nullable();        // target state the preview was computed against
            $table->char('result_fingerprint', 64)->nullable();      // target state right after this operation
            $table->json('summary')->nullable();                     // change summary
            $table->json('validation')->nullable();                  // errors / warnings / reference report
            $table->json('verification')->nullable();               // post-operation checks
            $table->uuid('backup_uuid')->nullable();
            $table->string('backup_path', 255)->nullable();          // relative to the private disk
            $table->char('backup_sha256', 64)->nullable();
            $table->string('upload_path', 255)->nullable();          // validated package kept until confirm / expiry
            $table->uuid('source_import_uuid')->nullable();          // rollback: the operation whose backup is restored
            $table->string('error_category', 40)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();

            $table->index(['client_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_imports');
    }
};
