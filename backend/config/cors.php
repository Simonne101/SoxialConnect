<?php

// CORS strict (docs/security.md §11) : origines explicites, jamais « * », cookies autorisés
// uniquement pour la SPA déclarée.
return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173')))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN', 'X-Request-Id'],

    'exposed_headers' => ['X-Request-Id', 'Retry-After'],

    'max_age' => 600,

    'supports_credentials' => true,

];
