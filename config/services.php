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

    'nova_poshta' => [
        'api_key' => env('NOVA_POSHTA_API_KEY'),
        'endpoint' => env('NOVA_POSHTA_API_URL', 'https://api.novaposhta.ua/v2.0/json/'),
        'postomat_type_ref' => 'f9316480-5f2d-425d-bc2c-ac7cd29decf0',
        'sender_city_ref' => env('NOVA_POSHTA_SENDER_CITY_REF', '8d5a980d-391c-11dd-90d9-001a92567626'),
    ],

    'free_delivery' => [
        'threshold' => (int) env('FREE_DELIVERY_THRESHOLD', 3000),
    ],

    'liqpay' => [
        'public_key' => env('LIQPAY_PUBLIC_KEY'),
        'private_key' => env('LIQPAY_PRIVATE_KEY'),
        'sandbox' => env('LIQPAY_SANDBOX', true),
        'result_url' => env('LIQPAY_RESULT_URL'),
        'server_url' => env('LIQPAY_SERVER_URL'),
        'checkout_url' => env('LIQPAY_CHECKOUT_URL', 'https://www.liqpay.ua/api/3/checkout'),
        'api_url' => env('LIQPAY_API_URL', 'https://www.liqpay.ua/api/request'),
    ],

    'monobank' => [
        'token' => env('MONOBANK_TOKEN'),
        'api_url' => env('MONOBANK_API_URL', 'https://api.monobank.ua'),
        'verify_webhook' => env('MONOBANK_VERIFY_WEBHOOK', true),
        'redirect_url' => env('MONOBANK_REDIRECT_URL'),
        'webhook_url' => env('MONOBANK_WEBHOOK_URL'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'google_analytics' => [
        'measurement_id' => env('GOOGLE_ANALYTICS_MEASUREMENT_ID'),
        'enabled' => env('GOOGLE_ANALYTICS_ENABLED', true),
    ],

    'meta_pixel' => [
        'pixel_id' => env('META_PIXEL_ID', '2193598034547819'),
        'enabled' => env('META_PIXEL_ENABLED', true),
    ],

    'google_customer_reviews' => [
        'enabled' => env('GOOGLE_CUSTOMER_REVIEWS_ENABLED', false),
        'merchant_id' => env('GOOGLE_MERCHANT_ID'),
        'delivery_days' => (int) env('GOOGLE_CUSTOMER_REVIEWS_DELIVERY_DAYS', 3),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 60),
    ],

];
