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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
        'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
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

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'bot_tenant_id' => (int) env('KACHING_BOT_TENANT_ID', 1),
    ],

    'ai' => [
        'provider'        => env('AI_PROVIDER', '9router'),
        'key'             => env('NINEROUTER_API_KEY') ?: env('GROQ_API_KEY'),
        'base_url'        => env('AI_API_BASE_URL', 'http://127.0.0.1:20128/v1'),
        'model'           => env('AI_MODEL', 'kachingcopilot'),
        'timeout'         => (int) env('AI_TIMEOUT', 20),
        'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 5),
    ],

    'groq' => [
        'key'      => env('GROQ_API_KEY') ?: env('NINEROUTER_API_KEY'),
        'base_url' => env('AI_API_BASE_URL', 'http://127.0.0.1:20128/v1'),
        'model'    => env('AI_MODEL', 'kachingcopilot'),
    ],

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
    ],

    'demand_forecast' => [
        'enabled' => (bool) env('DEMAND_FORECASTING_ENABLED', false),
    ],

    'catboost_serving' => [
        'enabled' => (bool) env('CATBOOST_SERVING_ENABLED', true),
        'base_url' => env('CATBOOST_SERVING_URL', 'http://127.0.0.1:8000'),
        'connect_timeout' => (int) env('CATBOOST_SERVING_CONNECT_TIMEOUT', 2),
        'timeout' => (int) env('CATBOOST_SERVING_TIMEOUT', 5),
        'max_retries' => (int) env('CATBOOST_SERVING_MAX_RETRIES', 1),
    ],

];
