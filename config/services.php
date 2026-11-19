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

    'realtimekit' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'app_id' => env('REALTIMEKIT_APP_ID'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'host_preset' => env('REALTIMEKIT_HOST_PRESET_NAME'),
        'participant_preset' => env('REALTIMEKIT_PARTICIPANT_PRESET_NAME'),
        'base_url' => env('REALTIMEKIT_API_BASE_URL', 'https://api.cloudflare.com/client/v4'),
        'timeout' => (int) env('REALTIMEKIT_HTTP_TIMEOUT', 15),
        'token_ttl_minutes' => (int) env('REALTIMEKIT_TOKEN_TTL_MINUTES', 60),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
