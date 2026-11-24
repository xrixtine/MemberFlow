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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    // Browser key: necessarily visible in the page, so it MUST be restricted in Google Cloud
    // (HTTP referrers = your app's origins; API restriction = Maps JavaScript API only).
    // map_id: Google's public demo Map ID works for development; create your own for production.
    'google_maps' => [
        'key' => env('GOOGLE_MAPS_KEY'),
        'map_id' => env('GOOGLE_MAPS_MAP_ID') ?: 'DEMO_MAP_ID',
    ],

    // Server key: only ever used by Laravel's WeatherService (never sent to the browser).
    'openweather' => [
        'key' => env('OPENWEATHER_KEY'),
        'url' => 'https://api.openweathermap.org/data/2.5/weather',
        'timeout' => (int) env('OPENWEATHER_TIMEOUT', 5),        // seconds, whole request
        'connect_timeout' => 3,                                  // seconds
        'cache_minutes' => (int) env('OPENWEATHER_CACHE_MINUTES', 10),
        'failure_cache_seconds' => 60,                           // back off after an error
        'upstream_per_minute' => 50,                             // free tier allows 60 calls/minute
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
