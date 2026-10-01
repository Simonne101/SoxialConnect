<?php

use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\MediaFileController;
use App\Http\Controllers\Api\V1\MediaFolderController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\SocialController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\EmailVerificationNotificationController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;

/*
|--------------------------------------------------------------------------
| API v1 — SocialPulse
|--------------------------------------------------------------------------
| Préfixe « api » ajouté par le framework. Authentification par session Sanctum (SPA),
| CSRF actif pour les requêtes de la SPA. Ordre de traitement de toute route :
| Authentification → Autorisation → Validation → Logique métier (docs/security.md §11).
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    Route::get('health', fn () => response()->json(['data' => ['status' => 'ok'], 'message' => null, 'meta' => (object) []]))
        ->name('health');

    // --- Authentification (Fortify, API seule) -------------------------------------------
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::middleware('guest:web')->group(function () {
            Route::post('login', [AuthenticatedSessionController::class, 'store'])
                ->middleware('throttle:login')->name('login');
            Route::post('register', [RegisteredUserController::class, 'store'])
                ->middleware('throttle:auth-sensitive')->name('register');
            Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
                ->middleware('throttle:auth-sensitive')->name('password.email');
            Route::post('reset-password', [NewPasswordController::class, 'store'])
                ->middleware('throttle:auth-sensitive')->name('password.update');
        });

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
            Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
                ->middleware('throttle:auth-sensitive')->name('verification.send');
            Route::put('password', [PasswordController::class, 'update'])
                ->middleware('throttle:auth-sensitive')->name('user-password.update');
        });
    });

    // --- Fichiers médias : URL signée courte (générée après autorisation), sans session requise.
    Route::get('media/{media}/file/{variant}', [MediaFileController::class, 'show'])
        ->whereUuid('media')
        ->whereIn('variant', ['original', 'thumbnail'])
        ->middleware(['signed', 'throttle:media-files'])
        ->withoutMiddleware('throttle:api')
        ->name('media.file');

    // --- Ressources authentifiées ---------------------------------------------------------
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('users/me', [MeController::class, 'show'])->name('users.me');
        Route::patch('users/me', [MeController::class, 'update'])->name('users.me.update');

        // Référentiel des plateformes (lecture seule, sans OAuth en phase 2).
        Route::get('social/platforms', [SocialController::class, 'platforms'])->name('social.platforms');

        // Les données de contenu exigent une adresse e-mail vérifiée (docs/security.md §3).
        Route::middleware('verified')->group(function () {
            Route::get('dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
            Route::get('social/accounts', [SocialController::class, 'accounts'])->name('social.accounts');
            // Connexion OAuth officielle (YouTube, TikTok) : limite « oauth » par utilisateur (docs/security.md §12).
            Route::post('social/{provider}/connect', [SocialController::class, 'connect'])
                ->whereIn('provider', ['youtube', 'tiktok'])
                ->middleware('throttle:oauth')->name('social.connect');
            Route::get('social/accounts/{account}/tiktok/creator-info', [SocialController::class, 'tiktokCreatorInfo'])
                ->whereUuid('account')->name('social.accounts.tiktok.creator-info');
            Route::post('social/accounts/{account}/sync', [SocialController::class, 'sync'])
                ->whereUuid('account')->middleware('throttle:oauth')->name('social.accounts.sync');
            Route::delete('social/accounts/{account}', [SocialController::class, 'disconnect'])
                ->whereUuid('account')->name('social.accounts.disconnect');
            Route::apiResource('content/posts', PostController::class)
                ->parameters(['posts' => 'post'])
                ->names('content.posts');
            Route::prefix('content/posts/{post}')->name('content.posts.')->controller(PostController::class)->group(function () {
                Route::post('duplicate', 'duplicate')->name('duplicate');
                Route::post('cancel', 'cancel')->name('cancel');
                Route::post('reschedule', 'reschedule')->name('reschedule');
                // Moteur de publication (docs/publishing.md) : limite « publish » par utilisateur.
                Route::post('publish', 'publish')->middleware('throttle:publish')->name('publish');
                Route::post('retry', 'retry')->middleware('throttle:publish')->name('retry');
                Route::get('attempts', 'attempts')->name('attempts');
            });

            // Médiathèque (upload : vérification d'e-mail + limite « uploads », docs/security.md §3 et §12).
            Route::prefix('media')->name('media.')->group(function () {
                Route::controller(MediaFolderController::class)->prefix('folders')->name('folders.')->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::post('/', 'store')->name('store');
                    Route::patch('{folder}', 'update')->whereUuid('folder')->name('update');
                    Route::delete('{folder}', 'destroy')->whereUuid('folder')->name('destroy');
                });
                Route::controller(MediaController::class)->group(function () {
                    Route::get('tags', 'tags')->name('tags');
                    Route::get('/', 'index')->name('index');
                    Route::post('/', 'store')->middleware('throttle:uploads')->name('store');
                    Route::get('{media}', 'show')->whereUuid('media')->name('show');
                    Route::patch('{media}', 'update')->whereUuid('media')->name('update');
                    Route::delete('{media}', 'destroy')->whereUuid('media')->name('destroy');
                    Route::post('{media}/tags', 'addTag')->whereUuid('media')->name('tags.store');
                    Route::delete('{media}/tags/{tag}', 'removeTag')->whereUuid('media')->name('tags.destroy');
                });
            });
        });
    });
});
