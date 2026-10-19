<?php

/*
 * IA indépendante du fournisseur (ADR-011, ADR-042). Préparation uniquement : aucun fournisseur
 * n'est implémenté ni appelé tant que la phase « Cœur IA » n'est pas livrée.
 *
 * Secrets : uniquement dans .env, jamais renvoyés au frontend ni journalisés.
 * Aucun modèle n'est imposé ici : chaque tâche lit son modèle dans .env (AI_MODEL_<TÂCHE>),
 * à défaut AI_DEFAULT_MODEL. Sans fournisseur ni modèle, la fonctionnalité est « non configurée ».
 */

$model = fn (string $task) => env('AI_MODEL_'.strtoupper($task), env('AI_DEFAULT_MODEL')) ?: null;

return [

    /* Fournisseur par défaut : openai | anthropic | gemini (null = IA désactivée). */
    'default_provider' => env('AI_DEFAULT_PROVIDER') ?: null,

    /* Modèle des tâches sans modèle propre (AI_MODEL_<TÂCHE>) ; null = aucun modèle choisi. */
    'default_model' => env('AI_DEFAULT_MODEL') ?: null,

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY') ?: null,
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY') ?: null,
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
        ],
        /*
         * Google Gemini, API « Interactions » (ADR-048). Modèles propres à Gemini, utilisés quand une
         * tâche est confiée à ce fournisseur (AI_<TÂCHE>_PROVIDER=gemini) : jamais un nom de modèle
         * d'un autre fournisseur. Transcription : non prise en charge par ce fournisseur.
         */
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY') ?: null,
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'models' => [
                'video_analysis' => env('GEMINI_MODEL_ANALYSIS') ?: null,
                'image' => env('GEMINI_MODEL_IMAGE') ?: null,
            ],
            // Analyse d'une vidéo YouTube par son URL : délai plus long qu'un appel texte (s).
            'video_timeout' => (int) env('GEMINI_VIDEO_TIMEOUT', 300),
            // Résolution des images générées (512, 1K, 2K, 4K).
            'image_size' => env('GEMINI_IMAGE_SIZE', '1K'),
        ],
    ],

    /* Délai maximal d'un appel au fournisseur (secondes) : inférieur au timeout du job « ai ». */
    'timeout' => (int) env('AI_TIMEOUT', 60),

    /*
     * Réglages par tâche (App\Domain\AI\Enums\AiTask). provider null = default_provider.
     * max_output_tokens borne la longueur (et le coût) de chaque réponse.
     */
    'tasks' => [
        'ideas' => ['provider' => null, 'model' => $model('ideas'), 'max_output_tokens' => 800, 'temperature' => 0.9],
        'post' => ['provider' => null, 'model' => $model('post'), 'max_output_tokens' => 1200, 'temperature' => 0.7],
        'caption' => ['provider' => null, 'model' => $model('caption'), 'max_output_tokens' => 600, 'temperature' => 0.7],
        'hashtags' => ['provider' => null, 'model' => $model('hashtags'), 'max_output_tokens' => 300, 'temperature' => 0.5],
        'rephrase' => ['provider' => null, 'model' => $model('rephrase'), 'max_output_tokens' => 1200, 'temperature' => 0.6],
        'tone' => ['provider' => null, 'model' => $model('tone'), 'max_output_tokens' => 1200, 'temperature' => 0.6],
        'adapt' => ['provider' => null, 'model' => $model('adapt'), 'max_output_tokens' => 2000, 'temperature' => 0.5],
        'script' => ['provider' => null, 'model' => $model('script'), 'max_output_tokens' => 2500, 'temperature' => 0.6],
        // YouTube Clipper (ADR-046) : choix des passages à partir de la transcription horodatée.
        // Analyse vidéo : les modèles « à réflexion » (Gemini 3.x) consomment une partie de ce budget avant
        // de répondre ; trop bas, le JSON est tronqué (réponse incomplète → INCOMPLETE_RESPONSE, nouvelle analyse).
        'video_analysis' => ['provider' => env('AI_VIDEO_ANALYSIS_PROVIDER') ?: null, 'url_provider' => env('AI_VIDEO_URL_ANALYSIS_PROVIDER') ?: null, 'url_fallback_model' => env('AI_VIDEO_URL_ANALYSIS_FALLBACK_MODEL') ?: null, 'model' => $model('video_analysis'), 'max_output_tokens' => (int) env('AI_VIDEO_ANALYSIS_MAX_OUTPUT_TOKENS', 8000), 'temperature' => 0.3],
        // Modèles spécialisés : jamais AI_DEFAULT_MODEL (modèle de texte), uniquement leur variable.
        'transcription' => ['provider' => env('AI_TRANSCRIPTION_PROVIDER') ?: null, 'model' => env('AI_MODEL_TRANSCRIPTION') ?: null],
        'image' => ['provider' => env('AI_IMAGE_PROVIDER') ?: null, 'model' => env('AI_MODEL_IMAGE') ?: null],
    ],

    /*
     * Transcription audio (YouTube Clipper) : délai d'un appel (fichiers longs) et taille maximale
     * acceptée par le fournisseur (OpenAI : 25 Mo).
     */
    'transcription' => [
        'timeout' => (int) env('AI_TRANSCRIPTION_TIMEOUT', 600),
        'max_file_mb' => 25,
    ],

    /*
     * Génération d'images (ADR-046) : taille demandée au fournisseur par orientation, puis recadrage
     * centré au format choisi (1:1, 4:5, 9:16, 16:9). Les dimensions réellement générées sont
     * conservées dans les métadonnées du média.
     */
    'images' => [
        'timeout' => (int) env('AI_IMAGE_TIMEOUT', 180),
        'max_prompt_chars' => 2000,
        'sizes' => [
            'square' => env('AI_IMAGE_SIZE_SQUARE', '1024x1024'),
            'portrait' => env('AI_IMAGE_SIZE_PORTRAIT', '1024x1536'),
            'landscape' => env('AI_IMAGE_SIZE_LANDSCAPE', '1536x1024'),
        ],
    ],

    /* Exécution asynchrone : file « ai » sur la connexion par défaut (appels < queue.connections.redis.retry_after). */
    'queue' => [
        'connection' => env('AI_QUEUE_CONNECTION') ?: null,
        'name' => env('AI_QUEUE', 'ai'),
    ],

    /* Longueur maximale de l'entrée utilisateur (caractères) : limite les abus et le coût. */
    'max_input_chars' => (int) env('AI_MAX_INPUT_CHARS', 8000),

    /*
     * Limites d'usage. Le débit par utilisateur est déjà borné par le limiteur « ai »
     * (config/security.php : RATE_LIMIT_AI_PER_MINUTE, RATE_LIMIT_AI_PER_DAY).
     * Budget : coût estimé cumulé par utilisateur et par jour, en millionièmes (null = sans plafond).
     */
    'budget' => [
        'daily_micros_per_user' => env('AI_DAILY_BUDGET_MICROS_PER_USER') !== null && env('AI_DAILY_BUDGET_MICROS_PER_USER') !== ''
            ? (int) env('AI_DAILY_BUDGET_MICROS_PER_USER')
            : null,
        'currency' => env('AI_BUDGET_CURRENCY', 'USD'),
    ],

    /*
     * Prix indicatifs par million de tokens, en millionièmes de la devise, par fournisseur puis
     * modèle : ['openai' => ['<modèle>' => ['input' => 0, 'output' => 0]]]. À renseigner au choix
     * du fournisseur ; sans prix connu, cost_estimate_micros reste NULL (jamais 0 inventé).
     */
    'pricing' => [],

    /* Cache des générations identiques (même tâche, version de prompt, entrée et modèle). */
    'cache' => [
        'enabled' => (bool) env('AI_CACHE_ENABLED', true),
        'ttl_hours' => (int) env('AI_CACHE_TTL_HOURS', 24),
    ],

];
