<?php

return [

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

    /*
    | Unihub gateway management API (docs/api/management-auth.md). The URL
    | includes the /internal/v1 prefix. Secrets come from the environment;
    | the assertion secret key lives in a file outside the web root.
    */
    'gateway' => [
        'url' => env('GATEWAY_MGMT_URL'),
        'token' => env('GATEWAY_MGMT_TOKEN'),
        'assertion_secret_key_path' => env('CMS_ASSERTION_SECRET_KEY_PATH'),
        'assertion_kid' => env('CMS_ASSERTION_KID', 'cms-1'),
        'assertion_ttl' => 60,
        'connect_timeout' => 5,
        'timeout' => 15,
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
