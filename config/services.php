<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // The storefront endpoint that drops its cached homepage (see FrontendRevalidator). The storefront address is
    // app.frontend_url (FRONTEND_URL); the secret must equal REVALIDATE_SECRET on the storefront.
    'frontend' => [
        'revalidate_secret' => env('FRONTEND_REVALIDATE_SECRET'),
    ],

    'erp' => [
        'order_webhook_url' => env('ERP_ORDER_WEBHOOK_URL'),
        'webhook_secret' => env('ERP_WEBHOOK_SECRET'),
        'internal_key' => env('INTERNAL_API_KEY'),
    ],

];
