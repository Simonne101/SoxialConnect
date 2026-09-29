<?php

use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\MeController;
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
            Route::apiResource('content/posts', PostController::class)
                ->parameters(['posts' => 'post'])
                ->names('content.posts');
            Route::prefix('content/posts/{post}')->name('content.posts.')->controller(PostController::class)->group(function () {
                Route::post('duplicate', 'duplicate')->name('duplicate');
                Route::post('cancel', 'cancel')->name('cancel');
                Route::post('reschedule', 'reschedule')->name('reschedule');
            });
        });
    });
});
