<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Purge physique des médias supprimés depuis plus de config('media.purge_after_days') jours.
Schedule::command('media:purge-deleted')->dailyAt('03:30')->withoutOverlapping();
