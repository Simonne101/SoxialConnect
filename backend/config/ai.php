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
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY') ?: null,
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
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
