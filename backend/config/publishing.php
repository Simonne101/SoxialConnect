<?php

use App\Domain\Publishing\Adapters\LinkedInPublisher;
use App\Domain\Publishing\Adapters\TelegramPublisher;
use App\Domain\Publishing\Adapters\TikTokPublisher;
use App\Domain\Publishing\Adapters\XPublisher;
use App\Domain\Publishing\Adapters\YouTubePublisher;

/*
 * Moteur de publication interne (docs/publishing.md).
 */
return [

    /*
     * Adaptateur par réseau : clé de plateforme (social_platforms.key) => classe implémentant
     * App\Domain\Publishing\Contracts\SocialPublisher. Tout réseau absent de cette liste utilise
     * NotConfiguredPublisher (aucun appel, aucun succès simulé). YouTube et TikTok : sans identifiants
     * (services.youtube, services.tiktok), l'adaptateur répond lui-même NOT_CONFIGURED.
     * Telegram : sans bot configuré (services.telegram.bot_token), NOT_CONFIGURED également.
     * X : texte uniquement (médias refusés avant tout envoi) ; sans identifiants X, NOT_CONFIGURED.
     */
    'adapters' => [
        'youtube' => YouTubePublisher::class,
        'tiktok' => TikTokPublisher::class,
        'telegram' => TelegramPublisher::class,
        'linkedin' => LinkedInPublisher::class,
        'x' => XPublisher::class,
    ],

    /* Tentatives automatiques par demande de publication (erreurs temporaires uniquement). */
    'max_auto_attempts' => (int) env('PUBLISHING_MAX_AUTO_ATTEMPTS', 3),

    /* Délai (secondes) avant la 2e, puis la 3e… tentative automatique. */
    'backoff' => [60, 300, 900],

    /* Publications dues passées en file à chaque passage du planificateur. */
    'dispatch_batch_size' => (int) env('PUBLISHING_DISPATCH_BATCH', 50),

    /*
     * Reprise après incident (minutes) : variante restée « en file » sans job (file vidée, worker
     * arrêté) → job renvoyé ; restée « en cours de publication » (worker tué) → échec au résultat
     * inconnu, jamais relancée automatiquement. Supérieurs au plus long backoff et au timeout du job.
     */
    'stale_queued_after_minutes' => (int) env('PUBLISHING_STALE_QUEUED_MINUTES', 30),
    'stale_publishing_after_minutes' => (int) env('PUBLISHING_STALE_PUBLISHING_MINUTES', 15),

    /*
     * Suivi des publications asynchrones (PublishOutcome::Processing, ex. TikTok) :
     * délais (secondes) avant chaque vérification, le dernier se répète ; délai maximal de
     * traitement (minutes) au-delà duquel le résultat devient « inconnu » ; balayage de secours
     * (minutes sans vérification) pour les suivis perdus.
     */
    'reconcile_delays' => [15, 30, 30, 60, 120],
    'processing_max_minutes' => (int) env('PUBLISHING_PROCESSING_MAX_MINUTES', 120),
    'processing_sweep_after_minutes' => (int) env('PUBLISHING_PROCESSING_SWEEP_MINUTES', 5),
];
