<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One homepage import (or rollback) attempt: validation session, audit record and backup pointer.
 *
 * Lifecycle (anything else is rejected by transitionTo):
 *   validated -> in_progress | expired
 *   in_progress -> succeeded | failed | recovery_required | rolled_back
 *   succeeded -> rolled_back | recovery_required
 *   recovery_required -> rolled_back
 * invalid, failed, expired and rolled_back are final.
 */
class HomepageImport extends Model
{
    public const KIND_IMPORT = 'import';

    public const KIND_ROLLBACK = 'rollback';

    public const VALIDATED = 'validated';

    public const IN_PROGRESS = 'in_progress';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const RECOVERY_REQUIRED = 'recovery_required';

    public const ROLLED_BACK = 'rolled_back';

    public const INVALID = 'invalid';

    public const EXPIRED = 'expired';

    public const TRANSITIONS = [
        self::VALIDATED => [self::IN_PROGRESS, self::EXPIRED],
        self::IN_PROGRESS => [self::SUCCEEDED, self::FAILED, self::RECOVERY_REQUIRED, self::ROLLED_BACK],
        self::SUCCEEDED => [self::ROLLED_BACK, self::RECOVERY_REQUIRED],
        self::RECOVERY_REQUIRED => [self::ROLLED_BACK],
        self::FAILED => [],
        self::ROLLED_BACK => [],
        self::INVALID => [],
        self::EXPIRED => [],
    ];

    protected $guarded = [];

    protected $casts = [
        'summary' => 'array',
        'validation' => 'array',
        'verification' => 'array',
        'schema_version' => 'integer',
        'expires_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function transitionTo(string $status, array $attributes = []): void
    {
        if (! in_array($status, self::TRANSITIONS[$this->status] ?? [], true)) {
            throw new LogicException("Homepage import cannot move from {$this->status} to {$status}.");
        }

        $this->forceFill(['status' => $status] + $attributes)->save();
    }

    public function isExpired(): bool
    {
        return $this->status === self::VALIDATED && $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** In progress for longer than the configured timeout: the process most likely died. */
    public function isInterrupted(): bool
    {
        return $this->status === self::IN_PROGRESS
            && $this->started_at !== null
            && $this->started_at->lt(Carbon::now()->subMinutes((int) config('homepage_transfer.in_progress_timeout_minutes')));
    }

    public function hasBackup(): bool
    {
        return $this->backup_path !== null && $this->backup_sha256 !== null;
    }
}
