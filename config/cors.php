<?php

/*
 * CORS for the storefront API. The storefront authenticates with a bearer token (no cookies), so
 * credentials stay disabled. Set CORS_ALLOWED_ORIGINS to a comma-separated list of storefront origins
 * in production (e.g. "https://storefront.example.com,https://www.example.com"); "*" allows any origin.
 */

return [
    'paths' => ['api', 'api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];