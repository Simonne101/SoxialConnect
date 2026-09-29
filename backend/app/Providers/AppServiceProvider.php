<?php

namespace App\Providers;

use App\Domain\Social\Security\TokenEncrypter;
use App\Listeners\RecordAuthenticationAudit;
use App\Models\User;
use App\Support\Logging\SecretRedactor;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Events\PasswordUpdatedViaController;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TokenEncrypter::class, fn () => TokenEncrypter::fromConfig(config('security.token_encryption')));
        $this->app->singleton(SecretRedactor::class, fn () => SecretRedactor::fromConfig());
    }

    public function boot(): void
    {
        // Modèles stricts hors production : pas de chargement paresseux caché, pas d'attribut ignoré en silence.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Politique de mot de passe (docs/security.md §3) : 12 caractères minimum ;
        // vérification des fuites (Have I Been Pwned, k-anonymat) en production uniquement.
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->max(128)->uncompromised()
            : Password::min(12)->max(128));

        $this->configureRateLimiting();
        $this->configureGates();
        $this->configureAudit();
    }

    /** Limites de débit (docs/security.md §12) — valeurs dans config/security.php. */
    private function configureRateLimiting(): void
    {
        // Valeurs lues à chaque requête (et non au démarrage) : ajustables par configuration.
        $limit = fn (string $key): int => (int) config("security.rate_limits.{$key}");
        $byUser = fn (Request $request) => $request->user()?->getAuthIdentifier() ?: $request->ip();

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute($limit('api_per_minute'))->by($byUser($r)));

        // Inscription, mot de passe oublié, réinitialisation, renvoi de vérification.
        RateLimiter::for('auth-sensitive', fn (Request $r) => [
            Limit::perMinute($limit('auth_sensitive_per_minute'))->by('min|'.$r->ip()),
            Limit::perHour($limit('auth_sensitive_per_hour'))->by('hour|'.$r->ip()),
        ]);

        // Prêtes pour les phases suivantes (upload, IA, publication, OAuth, webhooks).
        RateLimiter::for('uploads', fn (Request $r) => Limit::perHour($limit('uploads_per_hour'))->by($byUser($r)));
        RateLimiter::for('ai', fn (Request $r) => [
            Limit::perMinute($limit('ai_per_minute'))->by('min|'.$byUser($r)),
            Limit::perDay($limit('ai_per_day'))->by('day|'.$byUser($r)),
        ]);
        RateLimiter::for('publish', fn (Request $r) => Limit::perHour($limit('publish_per_hour'))->by($byUser($r)));
        RateLimiter::for('oauth', fn (Request $r) => Limit::perHour($limit('oauth_per_hour'))->by($byUser($r)));
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute($limit('webhooks_per_minute'))->by(($r->route('platform') ?? 'unknown').'|'.$r->ip()));
    }

    private function configureGates(): void
    {
        // Administration système (Horizon, commandes d'incident) : liste d'e-mails configurée.
        // Les rôles et permissions complets arriveront avec les workspaces (ADR-007).
        Gate::define('admin', fn (User $user) => $user->hasVerifiedEmail()
            && in_array(mb_strtolower($user->email), config('security.admin_emails', []), true));
    }

    private function configureAudit(): void
    {
        Event::listen(Login::class, [RecordAuthenticationAudit::class, 'onLogin']);
        Event::listen(Failed::class, [RecordAuthenticationAudit::class, 'onFailed']);
        Event::listen(Logout::class, [RecordAuthenticationAudit::class, 'onLogout']);
        Event::listen(Registered::class, [RecordAuthenticationAudit::class, 'onRegistered']);
        Event::listen(Verified::class, [RecordAuthenticationAudit::class, 'onVerified']);
        Event::listen(PasswordReset::class, [RecordAuthenticationAudit::class, 'onPasswordReset']);
        Event::listen(PasswordUpdatedViaController::class, [RecordAuthenticationAudit::class, 'onPasswordUpdated']);
    }
}
