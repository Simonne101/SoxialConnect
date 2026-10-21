<?php

// Médiathèque (docs/security.md §7, docs/architecture.md §4.6).
return [

    // Disque de stockage (config/filesystems.php) : local privé en dev, S3 ou compatible plus tard.
    'disk' => env('MEDIA_DISK', 'media'),

    // Formats autorisés : MIME DÉTECTÉ (magic bytes) → type, extension enregistrée et extensions
    // acceptées pour le nom d'origine. SVG, HTML, XML et exécutables ne sont jamais acceptés.
    'formats' => [
        'image/jpeg' => ['type' => 'image', 'extension' => 'jpg', 'aliases' => ['jpg', 'jpeg', 'jpe']],
        'image/png' => ['type' => 'image', 'extension' => 'png', 'aliases' => ['png']],
        'image/webp' => ['type' => 'image', 'extension' => 'webp', 'aliases' => ['webp']],
        'image/gif' => ['type' => 'image', 'extension' => 'gif', 'aliases' => ['gif']],
        'video/mp4' => ['type' => 'video', 'extension' => 'mp4', 'aliases' => ['mp4', 'm4v']],
        'video/quicktime' => ['type' => 'video', 'extension' => 'mov', 'aliases' => ['mov']],
        'video/webm' => ['type' => 'video', 'extension' => 'webm', 'aliases' => ['webm']],
        'audio/mpeg' => ['type' => 'audio', 'extension' => 'mp3', 'aliases' => ['mp3']],
        'audio/mp4' => ['type' => 'audio', 'extension' => 'm4a', 'aliases' => ['m4a']],
        'audio/x-m4a' => ['type' => 'audio', 'extension' => 'm4a', 'aliases' => ['m4a']],
        'audio/wav' => ['type' => 'audio', 'extension' => 'wav', 'aliases' => ['wav']],
        'audio/x-wav' => ['type' => 'audio', 'extension' => 'wav', 'aliases' => ['wav']],
        'application/pdf' => ['type' => 'document', 'extension' => 'pdf', 'aliases' => ['pdf']],
    ],

    // Tailles maximales par type (Mo). La limite effective est aussi bornée par PHP
    // (upload_max_filesize, post_max_size) et, en production, par Nginx.
    'max_size_mb' => [
        'image' => (int) env('MEDIA_MAX_IMAGE_MB', 20),
        'video' => (int) env('MEDIA_MAX_VIDEO_MB', 1024),
        'audio' => (int) env('MEDIA_MAX_AUDIO_MB', 200),
        'document' => (int) env('MEDIA_MAX_DOCUMENT_MB', 25),
    ],

    // Protection contre les bombes de décompression ; 40 Mpx ≈ 160 Mo de mémoire pour GD.
    'image' => [
        'max_side_px' => 12000,
        'max_pixels' => 40_000_000,
        'thumbnail_px' => 480,
        'quality' => 88,
    ],

    'tags' => [
        'max_per_media' => 20,
        'max_length' => 40,
    ],

    // Analyse antivirus (étape 8, lot 6). driver : « clamav » (démon clamd, protocole INSTREAM) ou « none ».
    // required = true : sans moteur joignable, les imports sont refusés (échec fermé).
    // required = false (développement sans ClamAV) : les fichiers sont acceptés mais restent « non analysés ».
    // clamd doit accepter les plus gros fichiers (StreamMaxLength, MaxFileSize, MaxScanSize ≥ MEDIA_MAX_VIDEO_MB),
    // sinon il refuse le flux et l'import échoue (jamais considéré comme sain).
    'scan' => [
        'driver' => env('MEDIA_SCAN_DRIVER', 'none'),
        'required' => (bool) env('MEDIA_SCAN_REQUIRED', env('APP_ENV') === 'production'),
        'clamav' => [
            'socket' => env('CLAMAV_SOCKET'),
            'host' => env('CLAMAV_HOST', '127.0.0.1'),
            'port' => (int) env('CLAMAV_PORT', 3310),
            'connect_timeout' => (float) env('CLAMAV_CONNECT_TIMEOUT', 2),
            'scan_timeout' => (float) env('CLAMAV_SCAN_TIMEOUT', 300),
        ],
    ],

    // Traitement (réencodage, vignettes) : synchrone tant qu'aucun worker de la queue « media »
    // ne tourne ; true = ProcessMediaJob sur la queue « media ».
    'process_async' => (bool) env('MEDIA_PROCESS_ASYNC', false),

    // Durée de validité des URLs signées de lecture (minutes).
    'url_ttl_minutes' => (int) env('MEDIA_URL_TTL_MINUTES', 15),

    // Purge physique des médias supprimés (commande media:purge-deleted).
    'purge_after_days' => (int) env('MEDIA_PURGE_AFTER_DAYS', 30),

    // Pagination de la liste.
    'per_page' => ['default' => 20, 'max' => 100],
];
