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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'weather' => [
        'base_url' => env('WEATHER_BASE_URL', 'https://api.open-meteo.com'),
        'latitude' => (float) env('WEATHER_LATITUDE', -31.9523),
        'longitude' => (float) env('WEATHER_LONGITUDE', 115.8613),
        'timezone' => env('WEATHER_TIMEZONE', 'Australia/Perth'),
        'location_name' => 'Perth, Australia',
        'cache_minutes' => (int) env('WEATHER_CACHE_MINUTES', 15),
        'timeout' => 5,
    ],

];
