<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Configure CORS settings for the frontend on Vercel and local development.
    |
    */
    // Include v1/* in case Vercel ever strips the /api prefix before CORS runs.
    'paths' => ['api/*', 'v1/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env(
        'CORS_ALLOWED_ORIGINS',
        'https://taxiscanner.vercel.app,http://localhost:3000,http://127.0.0.1:3000,http://localhost:5173'
    ))))),

    'allowed_origins_patterns' => [
        '#^https://.*\.vercel\.app$#',
        '#^http://localhost(:\d+)?$#',
        '#^http://127\.0\.0\.1(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => false,
];
