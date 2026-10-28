<?php

use App\Http\Controllers\OAuth\DataPortabilityCallbackController;
use App\Http\Controllers\OAuth\FacebookCallbackController;
use App\Http\Controllers\OAuth\InstagramCallbackController;
use App\Http\Controllers\OAuth\LinkedInCallbackController;
use App\Http\Controllers\OAuth\TikTokCallbackController;
use App\Http\Controllers\OAuth\XCallbackController;
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

// Retour OAuth de LinkedIn (authorization code) : même principe. Redirect URI HTTPS EXACTE
// déclarée dans le LinkedIn Developer Portal (LINKEDIN_REDIRECT_URI) ; en local, via le tunnel
// HTTPS puis le relais Vite (/oauth).
Route::get('/oauth/linkedin/callback', LinkedInCallbackController::class)
    ->middleware('throttle:20,1')
    ->name('oauth.linkedin.callback');

// Retour OAuth de X (authorization code + PKCE) : même principe. Redirect URI EXACTE déclarée
// dans la console développeur X (X_REDIRECT_URI) ; en local, via le tunnel HTTPS puis le relais Vite (/oauth).
Route::get('/oauth/x/callback', XCallbackController::class)
    ->middleware('throttle:20,1')
    ->name('oauth.x.callback');

// Retour OAuth de Facebook (Pages) : même principe. Redirect URI EXACTE déclarée dans l'app Meta
// (FACEBOOK_REDIRECT_URI) ; en local, via le tunnel HTTPS puis le relais Vite (/oauth).
Route::get('/oauth/facebook/callback', FacebookCallbackController::class)
    ->middleware('throttle:20,1')
    ->name('oauth.facebook.callback');

// Retour OAuth d'Instagram (connexion Instagram, comptes professionnels) : même principe. Redirect URI
// EXACTE déclarée dans les paramètres « Connexion Instagram » de l'app (INSTAGRAM_REDIRECT_URI).
Route::get('/oauth/instagram/callback', InstagramCallbackController::class)
    ->middleware('throttle:20,1')
    ->name('oauth.instagram.callback');

// Retour OAuth de Google pour l'export officiel Data Portability (ADR-054) : même principe. Redirect URI
// déclarée chez Google (DATA_PORTABILITY_REDIRECT_URI) ; en local, relayée par Vite (/oauth).
Route::get('/oauth/data-portability/callback', DataPortabilityCallbackController::class)
    ->middleware('throttle:20,1')
    ->name('oauth.data-portability.callback');
