<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Purge physique des médias supprimés depuis plus de config('media.purge_after_days') jours.
Schedule::command('media:purge-deleted')->dailyAt('03:30')->withoutOverlapping();
// Dossiers de travail de rendus vidéo abandonnés (worker arrêté brutalement), ADR-045.
Schedule::command('video:purge-temp')->dailyAt('03:45')->withoutOverlapping();

// Publication : mise en file des publications programmées arrivées à échéance + reprise des
// envois bloqués (docs/publishing.md §3). onOneServer : un seul passage si plusieurs serveurs.
Schedule::command('publishing:dispatch-due')->everyMinute()->withoutOverlapping(5)->onOneServer();
