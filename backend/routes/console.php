<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Purge physique des médias supprimés depuis plus de config('media.purge_after_days') jours.
Schedule::command('media:purge-deleted')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
// Dossiers de travail de rendus vidéo abandonnés (worker arrêté brutalement), ADR-045. Disque LOCAL de chaque
// serveur : volontairement sans onOneServer (chaque machine nettoie ses propres dossiers).
Schedule::command('video:purge-temp')->dailyAt('03:45')->withoutOverlapping();
// Sources vidéo du Clipper acquises automatiquement : purgées après conservation (ADR-049).
Schedule::command('clipper:expire-sources')->dailyAt('03:15')->withoutOverlapping()->onOneServer();
// Sources restées « indisponibles » faute de voie d'acquisition alors qu'une voie est désormais active (ADR-058).
Schedule::command('clipper:retry-unavailable-sources')->hourly()->withoutOverlapping()->onOneServer();
// Binaires vidéo vérifiés en CLI et partagés avec l'API, qui n'en exécute jamais (ExecutableCheck). Binaires LOCAUX :
// sur chaque serveur, sans onOneServer. Les autres tâches portent sur des données partagées : onOneServer (lot 7).
Schedule::command('video:check-executables')->everyTenMinutes()->withoutOverlapping();
// Statistiques (ADR-063) : collectes dues (aucune sans adaptateur déclaré) et conservation des réponses brutes.
Schedule::command('analytics:sync')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('analytics:purge')->dailyAt('03:55')->withoutOverlapping()->onOneServer();

// Publication : mise en file des publications programmées arrivées à échéance + reprise des
// envois bloqués (docs/publishing.md §3). onOneServer : un seul passage si plusieurs serveurs.
Schedule::command('publishing:dispatch-due')->everyMinute()->withoutOverlapping(5)->onOneServer();
