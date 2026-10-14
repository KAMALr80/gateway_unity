<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product image variants
    |--------------------------------------------------------------------------
    |
    | Remote product originals (synced from the ERP) are large PNGs on a slow host. The API exposes WebP
    | variants of them; each source is downloaded once, resized (never upscaled) and stored under
    | storage/app/private/{directory}. Only sources on the hosts below are ever fetched.
    |
    */

    'thumbnails' => [
        'enabled' => (bool) env('MEDIA_THUMBNAILS_ENABLED', true),

        // Longest side in pixels. 1024 is capped at the source size (no upscaling).
        'sizes' => [64, 256, 512, 1024],

        'source_hosts' => array_values(array_filter(array_map(
            fn (string $host) => strtolower(trim($host)),
            explode(',', (string) env('MEDIA_THUMBNAIL_SOURCE_HOSTS', 'erp.unitywholesales.com'))
        ))),

        'directory' => 'media-thumbs',
        // Public disk: generated files are served as static /storage files (needs `php artisan storage:link`).
        'disk' => env('MEDIA_THUMBNAIL_DISK', 'public'),
        'quality' => 80,

        'max_source_bytes' => 15 * 1024 * 1024,
        'max_source_pixels' => 16_000_000,
        'connect_timeout' => 5,
        'timeout' => 20,

        // After a failed download/decode the variant URL redirects to the original for this long.
        'failure_ttl' => 3600,
    ],

];
