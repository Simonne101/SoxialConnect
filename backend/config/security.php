<?php

// Paramètres de sécurité applicatifs (docs/security.md).
return [

    // Gate « admin » : comptes autorisés aux outils d'administration (Horizon, incidents…).
    // Rôles et permissions complets : avec les workspaces (ADR-007).
    'admin_emails' => array_values(array_filter(array_map(
        fn (string $e) => strtolower(trim($e)),
        explode(',', (string) env('ADMIN_EMAILS', ''))
    ))),

    // Reverse proxy(s) de confiance (X-Forwarded-*), lus ICI et non par env() dans bootstrap/app.php : après
    // « config:cache », env() n'y renvoie plus rien (ADR-072). Jamais « * » (filtré par TrustConfiguredProxies).
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) (env('TRUSTED_PROXIES') ?: '127.0.0.1,::1'))))),

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
        // Verrouillage progressif d'un compte depuis une même IP, et plafond par IP toutes adresses confondues
        // (credential stuffing). Code 2FA : par tentative de connexion en attente.
        'login_per_hour' => (int) env('RATE_LIMIT_LOGIN_PER_HOUR', 20),
        'login_ip_per_minute' => (int) env('RATE_LIMIT_LOGIN_IP_PER_MINUTE', 20),
        'login_ip_per_hour' => (int) env('RATE_LIMIT_LOGIN_IP_PER_HOUR', 100),
        'two_factor_per_minute' => (int) env('RATE_LIMIT_TWO_FACTOR_PER_MINUTE', 5),
        'auth_sensitive_per_minute' => (int) env('RATE_LIMIT_AUTH_SENSITIVE_PER_MINUTE', 3),
        'auth_sensitive_per_hour' => (int) env('RATE_LIMIT_AUTH_SENSITIVE_PER_HOUR', 20),
        'api_per_minute' => (int) env('RATE_LIMIT_API_PER_MINUTE', 120),
        'uploads_per_hour' => (int) env('RATE_LIMIT_UPLOADS_PER_HOUR', 30),
        'media_files_per_minute' => (int) env('RATE_LIMIT_MEDIA_FILES_PER_MINUTE', 600),
        'ai_per_minute' => (int) env('RATE_LIMIT_AI_PER_MINUTE', 20),
        'ai_per_day' => (int) env('RATE_LIMIT_AI_PER_DAY', 300),
        'publish_per_hour' => (int) env('RATE_LIMIT_PUBLISH_PER_HOUR', 60),
        'oauth_per_hour' => (int) env('RATE_LIMIT_OAUTH_PER_HOUR', 10),
        // Synchronisation manuelle des statistiques : appels externes soumis aux quotas des plateformes.
        'analytics_sync_per_hour' => (int) env('RATE_LIMIT_ANALYTICS_SYNC_PER_HOUR', 20),
        'webhooks_per_minute' => (int) env('RATE_LIMIT_WEBHOOKS_PER_MINUTE', 600),
    ],

    // Taille maximale d'un corps JSON envoyé à l'API, en Ko (413 au-delà ; étape 8, lot 7).
    'max_json_kb' => (int) env('API_MAX_JSON_KB', 1024),

    // Clés masquées dans les journaux et les métadonnées d'audit (docs/security.md §13).
    'redacted_keys' => [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'access_token', 'refresh_token', 'token', 'id_token', 'secret', 'client_secret',
        'api_key', 'apikey', 'authorization', 'cookie', 'set-cookie', 'session', 'x-xsrf-token',
        'signed_request', 'code', 'code_verifier', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token',
        'access_token_encrypted', 'refresh_token_encrypted',
    ],

];
