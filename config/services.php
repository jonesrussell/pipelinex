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

    'north_cloud' => [
        'crawler_url' => env('NORTH_CLOUD_CRAWLER_URL', 'http://localhost:8060'),
        'classifier_url' => env('NORTH_CLOUD_CLASSIFIER_URL', 'http://localhost:8071'),
        'internal_secret' => env('NORTH_CLOUD_INTERNAL_SECRET', ''),
    ],

    'pipelinex' => [
        'crawl_wait_timeout' => (int) env('CRAWL_WAIT_TIMEOUT', 30),
    ],

];
