<?php

/*
 * Quotas de sécurité (étape 8, lot 4 — ADR-071) : protègent le disque, les workers et la bande passante contre un
 * utilisateur (ou un script) qui enchaînerait les imports et les traitements vidéo. Valeurs par défaut prudentes,
 * toutes configurables ; 0 désactive le quota correspondant (déconseillé en production).
 */
return [

    // Stockage par utilisateur : originaux + fichiers dérivés (vignettes) de TOUS ses médias, y compris en
    // quarantaine, en traitement, générés (clips, images IA, sources acquises) ET supprimés logiquement tant que
    // la purge n'a pas libéré les fichiers ; plus les réservations en cours (imports et rendus non terminés).
    'storage' => [
        'max_mb' => (int) env('QUOTA_STORAGE_MB', 10240),
        // Réservation d'un rendu (clip, vidéo du Studio) le temps de produire le fichier.
        'render_reserve_mb' => (int) env('QUOTA_RENDER_RESERVE_MB', 300),
        // Quota restant minimal pour lancer une acquisition automatique (yt-dlp, export Google).
        'acquisition_min_mb' => (int) env('QUOTA_ACQUISITION_MIN_MB', 50),
        // Une réservation oubliée (worker tué) expire d'elle-même.
        'reservation_ttl_minutes' => (int) env('QUOTA_RESERVATION_TTL_MINUTES', 240),
    ],

    // Clipping par utilisateur. Projet « actif » : en import, analyse, transcription, détection ou rendu, ou prêt
    // avec un clip en cours de rendu. Un projet terminé, échoué ou annulé libère sa place aussitôt ; un projet
    // bloqué (worker tué) la libère après stale_after_minutes sans activité.
    'clipping' => [
        'max_active' => (int) env('QUOTA_CLIPPING_ACTIVE', 5),
        // Créations sur 24 heures glissantes ; un projet supprimé reste compté.
        'max_per_day' => (int) env('QUOTA_CLIPPING_PER_DAY', 20),
        'stale_after_minutes' => (int) env('QUOTA_CLIPPING_STALE_MINUTES', 240),
    ],

    // Espace disque global (volume du stockage des médias et des fichiers temporaires vidéo). Une opération
    // n'est lancée que s'il reste, après elle et les autres opérations en cours, au moins min_free_mb, avec une
    // marge de margin_percent sur sa propre taille.
    'disk' => [
        'enabled' => (bool) env('DISK_GUARD_ENABLED', true),
        'min_free_mb' => (int) env('DISK_GUARD_MIN_FREE_MB', 2048),
        'margin_percent' => (int) env('DISK_GUARD_MARGIN_PERCENT', 20),
        // Acquisition : fichier téléchargé puis copié dans la médiathèque → deux fois la taille maximale.
        'acquisition_factor' => 2,
        // Analyse (audio extrait, transcription) et rendu (dossier de travail + sortie).
        'analysis_reserve_mb' => (int) env('DISK_GUARD_ANALYSIS_RESERVE_MB', 512),
        'render_reserve_mb' => (int) env('DISK_GUARD_RENDER_RESERVE_MB', 2048),
        // Archive Google dont la taille n'est pas annoncée (Content-Length absent).
        'unknown_download_reserve_mb' => (int) env('DISK_GUARD_UNKNOWN_DOWNLOAD_MB', 4096),
    ],
];
