<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Responses\Auth\GenericPasswordResetLinkResponse;
use App\Http\Responses\Auth\JsonLoginResponse;
use App\Http\Responses\Auth\JsonRegisterResponse;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

/**
 * Fortify en mode API seule (ADR-005) : ses routes par défaut sont désactivées et redéclarées
 * sous /api/v1/auth (routes/api.php) avec nos propres limites de débit.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();

        $this->app->singleton(LoginResponse::class, JsonLoginResponse::class);
        $this->app->singleton(RegisterResponse::class, JsonRegisterResponse::class);
        // Mot de passe oublié : même réponse dans tous les cas (pas d'énumération d'e-mails).
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, fn ($app, $params) => new GenericPasswordResetLinkResponse($params['status'] ?? ''));
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, fn ($app, $params) => new GenericPasswordResetLinkResponse($params['status'] ?? ''));
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Connexion : e-mail insensible à la casse, comptes suspendus refusés
        // (message générique : aucune information sur l'existence du compte).
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', mb_strtolower(trim((string) $request->input('email'))))->first();

            if ($user && ! $user->isSuspended() && Hash::check((string) $request->input('password'), $user->password)) {
                return $user;
            }

            return null;
        });

        // Liens de réinitialisation : ils ouvrent la SPA, pas une vue Laravel.
        ResetPassword::createUrlUsing(fn (User $user, string $token) => rtrim((string) config('app.frontend_url'), '/')
            .'/reset-password?token='.urlencode($token).'&email='.urlencode($user->email));

        RateLimiter::for('login', function (Request $request) {
            $key = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(config('security.rate_limits.login_per_minute'))->by($key);
        });
    }
}
