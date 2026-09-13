<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The frontend calls both the /api/* REST routes and the root-level eGov
    | provider shims (/v1/liveness/*, /messaging/v1/sms/push, /api/token).
    | Laravel's built-in default only covers api/*, so browser calls to those
    | root paths had no CORS headers at all and silently fell back to mock
    | data. Ship an explicit config instead of relying on that default.
    |
    */

    'paths' => [
        'api/*',
        'v1/*',
        'messaging/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    // Comma-separated list in FRONTEND_URL, e.g.
    // "http://localhost:3000,https://gabaymed.example.ph".
    // Wildcard origins are intentionally not used: this API carries
    // authenticated citizen data.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
