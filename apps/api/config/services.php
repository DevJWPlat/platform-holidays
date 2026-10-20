<?php

return [

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'allowed_domain' => env('GOOGLE_ALLOWED_DOMAIN', 'platform.team'),
    ],

    'clickup' => [
        'token' => env('CLICKUP_API_TOKEN'),
        'list_id' => env('CLICKUP_LIST_ID', '1200650000011890'),
        'base_url' => env('CLICKUP_API_URL', 'https://api.clickup.com/api/v2'),

        // Some staff still use their older Built By Platform ClickUp accounts.
        // Keys are Platform Holidays emails; values are the ClickUp account emails.
        'user_email_map' => [
            'jonny@platform.team' => 'jonny@builtbyplatform.co.uk',
            'tom@platform.team' => 'thomas@builtbyplatform.co.uk',
            'neil@platform.team' => 'neil@builtbyplatform.co.uk',
            'christian@platform.team' => 'christian@builtbyplatform.co.uk',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

];
