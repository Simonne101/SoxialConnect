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

    /*
     * YouTube (Google OAuth 2.0 + YouTube Data API v3) — docs/publishing.md §11.
     * Valeurs uniquement dans .env (jamais dans Git). redirect : URI EXACTE déclarée dans la
     * console Google Cloud (ex. local : http://localhost:5173/oauth/youtube/callback).
     */
    'youtube' => [
        'client_id' => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'redirect' => env('YOUTUBE_REDIRECT_URI'),
        // Catégorie YouTube par défaut (22 = People & Blogs) ; null pour laisser YouTube choisir.
        'category_id' => env('YOUTUBE_DEFAULT_CATEGORY_ID', '22'),
    ],

    /*
     * TikTok (Login Kit Web + Content Posting API, Direct Post) — docs/publishing.md §12.
     * Valeurs uniquement dans .env. redirect : URI HTTPS EXACTE déclarée dans le portail TikTok
     * (en local, via le tunnel : https://<domaine-du-tunnel>/oauth/tiktok/callback).
     */
    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'redirect' => env('TIKTOK_REDIRECT_URI'),
        // Limites internes de l'envoi (TikTok accepte jusqu'à 4 Go, morceaux de 5 à 64 Mo) :
        // bornées pour tenir dans le délai du job de publication (300 s).
        'max_upload_mb' => (int) env('TIKTOK_MAX_UPLOAD_MB', 500),
        'chunk_size_mb' => 16,
    ],

    /*
     * LinkedIn (Sign In with LinkedIn using OpenID Connect + Share on LinkedIn) —
     * docs/publishing.md §14. Valeurs uniquement dans .env. redirect : URI HTTPS EXACTE déclarée
     * dans le LinkedIn Developer Portal (local : https://<domaine-du-tunnel>/oauth/linkedin/callback).
     */
    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect' => env('LINKEDIN_REDIRECT_URI'),
    ],

    /*
     * X (OAuth 2.0 Authorization Code + PKCE, client confidentiel) — docs/publishing.md §15.
     * Valeurs uniquement dans .env. redirect : URI EXACTE déclarée dans la console développeur X
     * (User authentication settings → Callback URI ; « localhost » y est refusé). Local, via le
     * tunnel : https://<domaine-du-tunnel>/oauth/x/callback.
     */
    'x' => [
        'client_id' => env('X_CLIENT_ID'),
        'client_secret' => env('X_CLIENT_SECRET'),
        'redirect' => env('X_REDIRECT_URI'),
    ],

    /*
     * Telegram (Bot API) — docs/publishing.md §13. UN bot partagé par toute la plateforme :
     * son token reste uniquement dans .env (jamais en base, jamais renvoyé au frontend, jamais
     * journalisé : il figure dans l'URL de chaque appel). Aucun token utilisateur.
     * webhook_secret : en-tête X-Telegram-Bot-Api-Secret-Token exigé sur le webhook
     * (1 à 256 caractères A-Z a-z 0-9 _ -). webhook_url : URL HTTPS publique du webhook
     * (en local, via le tunnel : https://<domaine-du-tunnel>/api/v1/webhooks/telegram).
     */
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'webhook_url' => env('TELEGRAM_WEBHOOK_URL'),
        'api_base' => 'https://api.telegram.org',
        // Limites du serveur Bot API officiel : 50 Mo par fichier envoyé, 10 Mo par photo.
        'max_upload_mb' => 50,
        'max_photo_mb' => 10,
    ],

];
