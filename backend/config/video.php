<?php

/*
 * Video Studio — projets « composition » (ADR-042). Préparation uniquement : aucun rendu n'est
 * exécuté avant la phase « Rendu ». FFmpeg et FFprobe ne sont PAS requis pour démarrer
 * l'application : leurs chemins ne sont lus qu'au moment d'un rendu.
 */

return [

    /* Moteur de rendu, enregistré sur chaque rendu (seul moteur : FFmpeg, ADR-045). */
    'renderer' => env('VIDEO_RENDERER') ?: 'ffmpeg',

    'binaries' => [
        // Nom dans le PATH ou chemin absolu (ex. C:\ffmpeg\bin\ffmpeg.exe) ; vide = valeur par défaut.
        'ffmpeg' => env('VIDEO_FFMPEG_BINARY') ?: 'ffmpeg',
        'ffprobe' => env('VIDEO_FFPROBE_BINARY') ?: 'ffprobe',
    ],

    /*
     * Limites V1 d'un projet (appliquées par les services Video ; la base garantit déjà les bornes
     * par scène). Elles bornent la taille d'un projet et le coût d'un rendu : vidéos sociales
     * courtes (90 s), quelques calques (8 pistes), un montage lisible (100 éléments par piste).
     */
    'limits' => [
        'max_duration_ms' => (int) env('VIDEO_MAX_DURATION_MS', 90_000),
        'max_scenes' => (int) env('VIDEO_MAX_SCENES', 20),
        'max_tracks' => (int) env('VIDEO_MAX_TRACKS', 8),
        'max_items_per_track' => (int) env('VIDEO_MAX_ITEMS_PER_TRACK', 100),
        'max_assets' => (int) env('VIDEO_MAX_ASSETS', 100),
        // 100 ms : en dessous, un élément n'occupe que 2 ou 3 images à 24-30 i/s.
        'item_min_duration_ms' => 100,
        'scene_min_duration_ms' => 500,
        'scene_max_duration_ms' => 30_000,
        'max_width' => (int) env('VIDEO_MAX_WIDTH', 1920),
        'max_height' => (int) env('VIDEO_MAX_HEIGHT', 1920),
    ],

    /* Dimensions de sortie par format (pixels, H.264 exige des dimensions paires). */
    'formats' => [
        '9:16' => ['width' => 1080, 'height' => 1920],
        '1:1' => ['width' => 1080, 'height' => 1080],
        '16:9' => ['width' => 1920, 'height' => 1080],
    ],
    'default_aspect_ratio' => '9:16',

    'fps' => [
        'default' => (int) env('VIDEO_DEFAULT_FPS', 30),
        'allowed' => [24, 25, 30],
    ],

    /*
     * Police des textes (drawtext). Vide : première police trouvée parmi les candidates (Windows,
     * puis Linux). Aucune police trouvée et un texte à rendre : le rendu échoue (FONT_UNAVAILABLE).
     */
    'font_file' => env('VIDEO_FONT_FILE') ?: null,
    'font_candidates' => [
        'C:/Windows/Fonts/arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
    ],

    /* Encodage du MP4 final : H.264 (yuv420p) + AAC, lecture web immédiate (+faststart). */
    'encoding' => [
        'video_preset' => env('VIDEO_X264_PRESET', 'veryfast'),
        'video_crf' => (int) env('VIDEO_X264_CRF', 20),
        'audio_bitrate' => '160k',
        'audio_sample_rate' => 48_000,
    ],

    /* Délai de détection des binaires (`-version`, secondes) : un premier lancement peut être lent. */
    'binary_check_timeout_seconds' => (int) env('VIDEO_BINARY_CHECK_TIMEOUT', 20),

    /* Délai maximal d'une analyse FFprobe (secondes). */
    'probe_timeout_seconds' => 30,

    'render' => [
        // Durée maximale d'un rendu (secondes) : timeout de RenderVideoJob, inférieur à
        // queue.connections.redis-video.retry_after (sinon un rendu en cours serait relancé).
        'timeout_seconds' => (int) env('VIDEO_RENDER_TIMEOUT', 1800),
        'connection' => env('VIDEO_QUEUE_CONNECTION', 'redis-video'),
        'queue' => env('VIDEO_QUEUE', 'video'),
    ],

    /*
     * Fichiers de travail d'un rendu : un dossier par rendu, supprimé à la fin ; une purge
     * planifiée (video:purge-temp) supprime les dossiers plus anciens que retention_hours.
     * Par défaut dans storage/ (disque du projet), jamais dans le dossier temporaire système.
     */
    'temp_path' => env('VIDEO_TEMP_PATH') ?: storage_path('app/private/tmp/video'),
    'temp_retention_hours' => (int) env('VIDEO_TEMP_RETENTION_HOURS', 24),

];
