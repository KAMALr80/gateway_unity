<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An account its owner deleted, remembered by email hash so the ERP's
 * contact sync leaves it deleted.
 */
class DeletedAccount extends Model
{
    public $timestamps = false;

    protected $fillable = ['email_hash', 'deleted_at'];

    protected $casts = ['deleted_at' => 'datetime'];

    public static function remember(string $email): void
    {
        static::updateOrCreate(['email_hash' => static::hashOf($email)], ['deleted_at' => now()]);
    }

    public static function isDeleted(string $email): bool
    {
        return static::where('email_hash', static::hashOf($email))->exists();
    }

    /** Signing up again brings the account back under the ERP's sync. */
    public static function forget(string $email): void
    {
        static::where('email_hash', static::hashOf($email))->delete();
    }

    private static function hashOf(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }
}
