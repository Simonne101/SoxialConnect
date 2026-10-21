<?php

use App\Domain\Analytics\Adapters\LinkedInMetricsAdapter;
use App\Domain\Analytics\Adapters\XMetricsAdapter;
use App\Domain\Analytics\Adapters\YouTubeMetricsAdapter;

/*
 * Statistiques des réseaux sociaux (étape 7 — ADR-062, ADR-063). Socle de collecte : aucune donnée n'est
 * simulée ; un réseau sans adaptateur déclaré est marqué « unsupported » (jamais de chiffre inventé).
 */
return [
    // Adaptateurs de collecte par clé de plateforme (App\Domain\Analytics\Contracts\MetricsAdapter).
    // YouTube : Data API v3 en lecture seule (P2 — ADR-064).
    // X : chaque lecture de l'API est facturée — déclaré seulement si ANALYTICS_X_ENABLED=true (P3 — ADR-067) ;
    // sinon aucun appel, ni manuel ni planifié.
    // LinkedIn : déclaré ; aucun appel tant que le jeton n'a pas le scope r_member_postAnalytics (ADR-067).
    'adapters' => [
        'youtube' => YouTubeMetricsAdapter::class,
        'x' => env('ANALYTICS_X_ENABLED', false) ? XMetricsAdapter::class : null,
        'linkedin' => LinkedInMetricsAdapter::class,
    ],

    'linkedin' => [
        // En-tête Linkedin-Version (AAAAMM) des API /rest versionnées ; une version retirée par LinkedIn est
        // refusée (INVALID_REQUEST) : la mettre à jour ici.
        'api_version' => env('ANALYTICS_LINKEDIN_API_VERSION', '202609'),
    ],

    'sync' => [
        // File dédiée, servie par le worker général (scripts/dev.mjs) : appels externes à faible priorité.
        'queue' => env('ANALYTICS_QUEUE', 'analytics'),
        // Fenêtre des statistiques de compte demandées à chaque synchronisation (jours).
        'account_window_days' => (int) env('ANALYTICS_ACCOUNT_WINDOW_DAYS', 30),
        // Publications suivies : publiées depuis moins de N jours.
        'post_window_days' => (int) env('ANALYTICS_POST_WINDOW_DAYS', 90),
        // Délai minimal entre deux synchronisations réussies d'un même élément (secondes).
        'min_interval_seconds' => (int) env('ANALYTICS_MIN_INTERVAL_SECONDS', 3600),
        // Nouvelles tentatives après un échec temporaire : délais successifs (secondes), puis abandon.
        'backoff' => [300, 1800, 7200],
        // Verrou empêchant deux synchronisations simultanées du même élément (secondes).
        'lock_seconds' => 600,
    ],

    // Réponses brutes (analytics_snapshots) conservées 13 mois ; les métriques normalisées sont conservées
    // (docs/database.md §3.7).
    'retention' => [
        'raw_months' => (int) env('ANALYTICS_RAW_RETENTION_MONTHS', 13),
    ],
];
