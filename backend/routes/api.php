<?php

use App\Domain\Video\Enums\VideoProjectKind;
use App\Http\Controllers\Api\V1\AiImageController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\Clipper\ClipController;
use App\Http\Controllers\Api\V1\Clipper\ClipperProjectController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\MediaFileController;
use App\Http\Controllers\Api\V1\MediaFolderController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\SocialController;
use App\Http\Controllers\Api\V1\Video\VideoAssetController;
use App\Http\Controllers\Api\V1\Video\VideoProjectController;
use App\Http\Controllers\Api\V1\Video\VideoRenderController;
use App\Http\Controllers\Api\V1\Video\VideoSceneController;
use App\Http\Controllers\Api\V1\Video\VideoTimelineController;
use App\Http\Controllers\Api\V1\Video\VideoTrackController;
use App\Http\Controllers\Webhooks\TelegramWebhookController;
use App\Models\VideoProject;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\ConfirmedPasswordStatusController;
use Laravel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\EmailVerificationNotificationController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\TwoFactorQrCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorSecretKeyController;

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
            // Second facteur (étape 8, lot 5) : code TOTP ou code de secours, après un mot de passe valide.
            Route::post('two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'store'])
                ->middleware('throttle:two-factor')->name('two-factor.login.store');
        });

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
            Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
                ->middleware('throttle:auth-sensitive')->name('verification.send');
            Route::put('password', [PasswordController::class, 'update'])
                ->middleware('throttle:auth-sensitive')->name('user-password.update');

            // Confirmation du mot de passe (3 h, config auth.password_timeout) : exigée par les actions sensibles
            // (middleware « password.confirm » → 423 tant qu'elle n'a pas eu lieu).
            Route::post('user/confirm-password', [ConfirmablePasswordController::class, 'store'])
                ->middleware('throttle:auth-sensitive')->name('password.confirm.store');
            Route::get('user/confirmed-password-status', [ConfirmedPasswordStatusController::class, 'show'])->name('password.confirmation');

            // Gestion de la 2FA : toujours derrière une confirmation récente du mot de passe.
            Route::middleware('password.confirm')->group(function () {
                Route::post('user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'store'])->name('two-factor.enable');
                Route::post('user/confirmed-two-factor-authentication', [ConfirmedTwoFactorAuthenticationController::class, 'store'])
                    ->middleware('throttle:two-factor')->name('two-factor.confirm');
                Route::delete('user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'destroy'])->name('two-factor.disable');
                Route::get('user/two-factor-qr-code', [TwoFactorQrCodeController::class, 'show'])->name('two-factor.qr-code');
                Route::get('user/two-factor-secret-key', [TwoFactorSecretKeyController::class, 'show'])->name('two-factor.secret-key');
                Route::get('user/two-factor-recovery-codes', [RecoveryCodeController::class, 'index'])->name('two-factor.recovery-codes');
                Route::post('user/two-factor-recovery-codes', [RecoveryCodeController::class, 'store'])->name('two-factor.regenerate-recovery-codes');
            });
        });
    });

    // --- Fichiers médias : URL signée courte (générée après autorisation), sans session requise.
    Route::get('media/{media}/file/{variant}', [MediaFileController::class, 'show'])
        ->whereUuid('media')
        ->whereIn('variant', ['original', 'thumbnail'])
        ->middleware(['signed', 'throttle:media-files'])
        ->withoutMiddleware('throttle:api')
        ->name('media.file');

    // --- Webhook Telegram : authentifié par l'en-tête secret (pas de session) ; limite « webhooks ».
    Route::post('webhooks/{platform}', TelegramWebhookController::class)
        ->whereIn('platform', ['telegram'])
        ->middleware('throttle:webhooks')
        ->withoutMiddleware('throttle:api')
        ->name('webhooks.telegram');

    // --- Ressources authentifiées ---------------------------------------------------------
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('users/me', [MeController::class, 'show'])->name('users.me');
        Route::patch('users/me', [MeController::class, 'update'])->name('users.me.update');

        // Référentiel des plateformes et comptes de l'utilisateur : lecture seule, accessible dès la
        // création du compte (un nouvel utilisateur voit la liste vide et les réseaux à connecter).
        // Connecter, synchroniser ou déconnecter un réseau exige une adresse vérifiée (groupe ci-dessous).
        Route::get('social/platforms', [SocialController::class, 'platforms'])->name('social.platforms');
        Route::get('social/accounts', [SocialController::class, 'accounts'])->name('social.accounts');

        // Les données de contenu exigent une adresse e-mail vérifiée (docs/security.md §3).
        Route::middleware('verified')->group(function () {
            Route::get('dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
            // Statistiques réelles (étape 7, ADR-063) : lecture de ce qui a été collecté ; synchronisation manuelle
            // d'un compte (limite « analytics-sync » : quotas des plateformes).
            Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
            Route::post('analytics/sync', [AnalyticsController::class, 'sync'])->middleware('throttle:analytics-sync')->name('analytics.sync');
            // Connexion OAuth officielle (YouTube, TikTok, LinkedIn, X, Facebook, Instagram) : limite « oauth » par utilisateur (docs/security.md §12).
            Route::post('social/{provider}/connect', [SocialController::class, 'connect'])
                ->whereIn('provider', ['youtube', 'tiktok', 'linkedin', 'x', 'facebook', 'instagram'])
                ->middleware('throttle:oauth')->name('social.connect');
            // Telegram (bot partagé, sans OAuth) : lien de liaison d'une chaîne ou d'un groupe.
            Route::post('social/telegram/link', [SocialController::class, 'telegramLink'])
                ->middleware('throttle:oauth')->name('social.telegram.link');
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

            // Video Studio (ADR-044) : projets, scènes, médias du projet, pistes, éléments de timeline,
            // rendus. Toute ressource d'un autre compte répond 404 (politiques). Rendu : file
            // redis-video, FFmpeg puis FFprobe, MP4 publié dans la médiathèque (ADR-045).
            // Routes du Clipper : uniquement les projets de clipping (404 sinon). Côté Video Studio,
            // l'écriture sur un projet qui n'est pas une composition est refusée (409 NOT_COMPOSITION).
            Route::bind('clippingProject', fn (string $id) => VideoProject::query()->where('kind', VideoProjectKind::Clipping->value)->findOrFail($id));

            // YouTube Clipper URL-first (ADR-046, ADR-048, ADR-049) : lien YouTube → analyse (Gemini) →
            // source autorisée → recalage → clips ≤ 60 s (moteur FFmpeg, file redis-video) → médiathèque.
            Route::prefix('clipper')->name('clipper.')->group(function () {
                Route::post('lookup', [ClipperProjectController::class, 'lookup'])->middleware('throttle:ai')->name('lookup');
                Route::get('projects', [ClipperProjectController::class, 'index'])->name('projects.index');
                Route::post('projects', [ClipperProjectController::class, 'store'])->middleware('throttle:ai')->name('projects.store');
                Route::get('projects/{clippingProject}', [ClipperProjectController::class, 'show'])->whereUuid('clippingProject')->name('projects.show');
                Route::delete('projects/{clippingProject}', [ClipperProjectController::class, 'destroy'])->whereUuid('clippingProject')->name('projects.destroy');
                Route::put('projects/{clippingProject}/source', [ClipperProjectController::class, 'attachSource'])->whereUuid('clippingProject')->name('projects.source');
                // ADR-049 : relance de l'acquisition automatique de la source et de l'analyse.
                Route::post('projects/{clippingProject}/source/acquire', [ClipperProjectController::class, 'acquireSource'])->whereUuid('clippingProject')->middleware('throttle:ai')->name('projects.source.acquire');
                // ADR-054 : consentement à l'export officiel Google (Data Portability) de la chaîne propriétaire.
                Route::post('projects/{clippingProject}/source/consent', [ClipperProjectController::class, 'sourceConsent'])->whereUuid('clippingProject')->middleware('throttle:oauth')->name('projects.source.consent');
                Route::post('projects/{clippingProject}/reanalyze', [ClipperProjectController::class, 'reanalyze'])->whereUuid('clippingProject')->middleware('throttle:ai')->name('projects.reanalyze');
                // ADR-051 : annulation coopérative du traitement en cours (analyse, acquisition, rendus).
                Route::post('projects/{clippingProject}/cancel', [ClipperProjectController::class, 'cancel'])->whereUuid('clippingProject')->name('projects.cancel');
                Route::post('projects/{clippingProject}/clips', [ClipController::class, 'store'])->whereUuid('clippingProject')->middleware('throttle:publish')->name('clips.store');
                Route::post('clips/{clip}/render', [ClipController::class, 'render'])->whereUuid('clip')->middleware('throttle:publish')->name('clips.render');
            });

            // Génération d'images par IA (ADR-046) : images enregistrées dans la médiathèque.
            Route::prefix('ai/images')->name('ai.images.')->group(function () {
                Route::get('/', [AiImageController::class, 'index'])->name('index');
                Route::post('/', [AiImageController::class, 'store'])->middleware('throttle:ai')->name('store');
                Route::get('{generation}', [AiImageController::class, 'show'])->whereUuid('generation')->name('show');
            });

            Route::prefix('video')->name('video.')->group(function () {
                Route::apiResource('projects', VideoProjectController::class)
                    ->parameters(['projects' => 'project'])->names('projects')->whereUuid('project');
                Route::prefix('projects/{project}')->whereUuid('project')->name('projects.')->group(function () {
                    Route::get('scenes', [VideoSceneController::class, 'index'])->name('scenes.index');
                    Route::post('scenes', [VideoSceneController::class, 'store'])->name('scenes.store');
                    Route::get('tracks', [VideoTrackController::class, 'index'])->name('tracks.index');
                    Route::post('tracks', [VideoTrackController::class, 'store'])->name('tracks.store');
                    Route::get('timeline', [VideoTimelineController::class, 'show'])->name('timeline');
                    Route::get('assets', [VideoAssetController::class, 'index'])->name('assets.index');
                    Route::post('assets', [VideoAssetController::class, 'store'])->name('assets.store');
                    Route::get('renders', [VideoRenderController::class, 'index'])->name('renders.index');
                    Route::post('renders', [VideoRenderController::class, 'store'])->middleware('throttle:publish')->name('renders.store');
                });
                Route::patch('scenes/{scene}', [VideoSceneController::class, 'update'])->whereUuid('scene')->name('scenes.update');
                Route::delete('scenes/{scene}', [VideoSceneController::class, 'destroy'])->whereUuid('scene')->name('scenes.destroy');
                Route::patch('tracks/{track}', [VideoTrackController::class, 'update'])->whereUuid('track')->name('tracks.update');
                Route::delete('tracks/{track}', [VideoTrackController::class, 'destroy'])->whereUuid('track')->name('tracks.destroy');
                Route::post('tracks/{track}/items', [VideoTimelineController::class, 'store'])->whereUuid('track')->name('items.store');
                Route::patch('timeline-items/{item}', [VideoTimelineController::class, 'update'])->whereUuid('item')->name('items.update');
                Route::delete('timeline-items/{item}', [VideoTimelineController::class, 'destroy'])->whereUuid('item')->name('items.destroy');
                Route::delete('assets/{asset}', [VideoAssetController::class, 'destroy'])->whereUuid('asset')->name('assets.destroy');
                Route::get('renders/{render}', [VideoRenderController::class, 'show'])->whereUuid('render')->name('renders.show');
                Route::post('renders/{render}/cancel', [VideoRenderController::class, 'cancel'])->whereUuid('render')->name('renders.cancel');
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
