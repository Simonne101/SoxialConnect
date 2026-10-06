<?php

// Paramètres de sécurité applicatifs (docs/security.md).
return [

    // Gate « admin » : comptes autorisés aux outils d'administration (Horizon, incidents…).
    // Rôles et permissions complets : avec les workspaces (ADR-007).
    'admin_emails' => array_values(array_filter(array_map(
        fn (string $e) => strtolower(trim($e)),
        explode(',', (string) env('ADMIN_EMAILS', ''))
    ))),

    // Chiffrement des tokens OAuth : clé dédiée et versionnée, distincte d'APP_KEY (ADR-008).
    'token_encryption' => [
        'key' => env('TOKEN_ENCRYPTION_KEY'),
        'version' => (int) env('TOKEN_ENCRYPTION_KEY_VERSION', 1),
        // « 1:base64:...,2:base64:... » — anciennes clés conservées pendant la rotation.
        'previous_keys' => env('TOKEN_ENCRYPTION_PREVIOUS_KEYS', ''),
        'cipher' => 'aes-256-gcm',
    ],

    // Limites de débit (docs/security.md §12), configurables par environnement.
    'rate_limits' => [
        'login_per_minute' => (int) env('RATE_LIMIT_LOGIN_PER_MINUTE', 5),
        'auth_sensitive_per_minute' => (int) env('RATE_LIMIT_AUTH_SENSITIVE_PER_MINUTE', 3),
        'auth_sensitive_per_hour' => (int) env('RATE_LIMIT_AUTH_SENSITIVE_PER_HOUR', 20),
        'api_per_minute' => (int) env('RATE_LIMIT_API_PER_MINUTE', 120),
        'uploads_per_hour' => (int) env('RATE_LIMIT_UPLOADS_PER_HOUR', 30),
        'media_files_per_minute' => (int) env('RATE_LIMIT_MEDIA_FILES_PER_MINUTE', 600),
        'ai_per_minute' => (int) env('RATE_LIMIT_AI_PER_MINUTE', 20),
        'ai_per_day' => (int) env('RATE_LIMIT_AI_PER_DAY', 300),
        'publish_per_hour' => (int) env('RATE_LIMIT_PUBLISH_PER_HOUR', 60),
        'oauth_per_hour' => (int) env('RATE_LIMIT_OAUTH_PER_HOUR', 10),
        'webhooks_per_minute' => (int) env('RATE_LIMIT_WEBHOOKS_PER_MINUTE', 600),
    ],

    // Clés masquées dans les journaux et les métadonnées d'audit (docs/security.md §13).
    'redacted_keys' => [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'access_token', 'refresh_token', 'token', 'id_token', 'secret', 'client_secret',
        'api_key', 'apikey', 'authorization', 'cookie', 'set-cookie', 'session', 'x-xsrf-token',
        'signed_request', 'code', 'code_verifier', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token',
        'access_token_encrypted', 'refresh_token_encrypted',
    ],

];
