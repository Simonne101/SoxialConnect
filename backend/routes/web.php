<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\VerifyEmailController;

// Le backend ne sert pas d'interface : l'application est la SPA React.
Route::get('/', fn () => redirect()->away(config('app.frontend_url')));

// Lien de vérification envoyé par e-mail : ouvert directement dans le navigateur (session web),
// puis redirection vers la SPA (config fortify.home) avec ?verified=1.
Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['auth:web', 'signed', 'throttle:6,1'])
    ->name('verification.verify');
