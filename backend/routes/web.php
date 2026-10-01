<?php

use App\Http\Controllers\OAuth\TikTokCallbackController;
use App\Http\Controllers\OAuth\YouTubeCallbackController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\VerifyEmailController;

// Le backend ne sert pas d'interface : l'application est la SPA React.
Route::get('/', fn () => redirect()->away(config('app.frontend_url')));

// Lien de vérification envoyé par e-mail : ouvert directement dans le navigateur (session web),
// puis redirection vers la SPA (config fortify.home) avec ?verified=1.
Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['auth:web', 'signed', 'throttle:6,1'])
    ->name('verification.verify');

// Retour OAuth de Google (YouTube) : route web pour disposer de la session de l'utilisateur.
// Redirect URI déclarée chez Google (YOUTUBE_REDIRECT_URI) ; en local, relayée par Vite (/oauth).
Route::get('/oauth/youtube/callback', YouTubeCallbackController::class)
    ->middleware('throttle:20,1')
    ->name('oauth.youtube.callback');

// Retour OAuth de TikTok (Login Kit Web) : même principe. Redirect URI HTTPS obligatoire
// (TIKTOK_REDIRECT_URI) ; en local, via le tunnel HTTPS puis le relais Vite (/oauth).
Route::get('/oauth/tiktok/callback', TikTokCallbackController::class)
    ->middleware('throttle:20,1')
    ->name('oauth.tiktok.callback');
