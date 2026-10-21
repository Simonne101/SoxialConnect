<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Responses\Auth\GenericPasswordResetLinkResponse;
use App\Http\Responses\Auth\JsonLoginResponse;
use App\Http\Responses\Auth\JsonRegisterResponse;
use App\Http\Responses\Auth\JsonTwoFactorLoginResponse;
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
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
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
        $this->app->singleton(TwoFactorLoginResponse::class, JsonTwoFactorLoginResponse::class);
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
        // Adresse inconnue : un hachage factice est quand même vérifié, pour que le temps de réponse ne révèle
        // pas l'existence du compte (étape 8, lot 5).
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', mb_strtolower(trim((string) $request->input('email'))))->first();
            $valid = Hash::check((string) $request->input('password'), $user?->password ?? self::dummyHash());

            return $user && $valid && ! $user->isSuspended() ? $user : null;
        });

        // Liens de réinitialisation : ils ouvrent la SPA, pas une vue Laravel.
        ResetPassword::createUrlUsing(fn (User $user, string $token) => rtrim((string) config('app.frontend_url'), '/')
            .'/reset-password?token='.urlencode($token).'&email='.urlencode($user->email));

        // Connexion : par adresse + IP (essais sur un compte, verrouillage progressif : minute puis heure) ET par IP
        // seule (« credential stuffing » : une adresse différente à chaque essai). Pas de limite par adresse seule :
        // un tiers pourrait bloquer le compte d'une victime depuis n'importe où.
        RateLimiter::for('login', function (Request $request) {
            $account = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());
            $limits = config('security.rate_limits');

            return [
                Limit::perMinute($limits['login_per_minute'])->by('account-minute:'.$account),
                Limit::perHour($limits['login_per_hour'])->by('account-hour:'.$account),
                Limit::perMinute($limits['login_ip_per_minute'])->by('ip-minute:'.$request->ip()),
                Limit::perHour($limits['login_ip_per_hour'])->by('ip-hour:'.$request->ip()),
            ];
        });

        // Code 2FA : limité par tentative de connexion en attente (session) et par IP.
        RateLimiter::for('two-factor', fn (Request $request) => [
            Limit::perMinute(config('security.rate_limits.two_factor_per_minute'))->by('2fa:'.($request->session()->get('login.id') ?? '').'|'.$request->ip()),
            Limit::perMinute(config('security.rate_limits.login_ip_per_minute'))->by('2fa-ip:'.$request->ip()),
        ]);
    }

    /** Hachage Argon2id sans compte associé (calculé une fois par processus). */
    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(40));
    }
}
