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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'skysms' => [
        'url' => env('SKYSMS_API_URL', 'https://skysms.skyio.site/api/v1/sms'),
        'key' => env('SKYSMS_API_KEY', 'sk_deoYwH46rnXLBJUFbzoSbSyR0lOYzuQK'),
    ],

    'couriers' => [
        'flash' => [
            'base_url'       => env('FLASH_API_URL', 'https://open-api.flashexpress.com'),
            'mch_id'         => env('FLASH_MCH_ID'),
            'secret_key'     => env('FLASH_SECRET_KEY'),
        ],
        'jnt' => [
            'base_url'       => env('JNT_API_URL', 'https://openapi.jtexpress.ph/api'),
            'api_key'        => env('JNT_API_KEY'),
            'api_secret'     => env('JNT_API_SECRET'),
            'webhook_secret' => env('JNT_WEBHOOK_SECRET'),
        ],
    ],

];
