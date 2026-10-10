<?php

/*
 * Homepage import/export (admin only). See docs/homepage-transfer.md.
 *
 * Every limit below is enforced on the uploaded package before its content is interpreted.
 */
return [
    'package_type' => 'homepage',
    'schema_version' => 1,
    'supported_schema_versions' => [1],

    // Upload / archive limits.
    'max_upload_kb' => 102400,
    'max_entries' => 400,
    'max_json_bytes' => 5 * 1024 * 1024,
    'max_image_bytes' => 5 * 1024 * 1024,      // same as the admin upload rule
    'max_pdf_bytes' => 50 * 1024 * 1024,       // same as the admin upload rule
    'max_total_uncompressed_bytes' => 200 * 1024 * 1024,
    'max_compression_ratio' => 200,
    'max_json_depth' => 32,

    // Homepage structure limits.
    'max_sections' => 50,
    'max_items_per_section' => 200,

    // A validated package can be confirmed for this long; an import left "in_progress" this long is interrupted.
    'validation_ttl_minutes' => 60,
    'in_progress_timeout_minutes' => 15,
    'lock_seconds' => 300,

    // Private storage (disk "local" is storage/app/private, never web-served).
    'private_disk' => 'local',
    'backup_directory' => 'homepage-backups',
    'upload_directory' => 'homepage-imports',

    // Informational manifest fields. Never put a URL, host or secret in these.
    'environment_label' => env('HOMEPAGE_TRANSFER_ENV'),
    'source_commit' => env('APP_GIT_COMMIT'),

    'rate_limit_per_minute' => 10,
];
