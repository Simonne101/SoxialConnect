<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RejectMalformedJson;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SPA : session + CSRF Sanctum pour les requêtes venant des domaines déclarés (SANCTUM_STATEFUL_DOMAINS).
        $middleware->statefulApi();
        // Limite générale de l'API (limiteur « api », docs/security.md §12).
        $middleware->throttleApi();

        // Proxies de confiance : par défaut UNIQUEMENT le proxy local (serveur Vite en développement,
        // Nginx sur la même machine en production). Ses en-têtes X-Forwarded-* (dont Proto: https
        // derrière un tunnel HTTPS) sont alors pris en compte ; jamais ceux d'une autre adresse.
        // (env() : le fichier de configuration n'est pas encore chargé à ce stade.)
        $middleware->trustProxies(at: array_values(array_filter(array_map(
            'trim',
            explode(',', (string) (env('TRUSTED_PROXIES') ?: '127.0.0.1,::1')),
        ), fn (string $ip) => $ip !== '' && $ip !== '*' && $ip !== '**')));

        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->api(prepend: [RejectMalformedJson::class]);

        // « guest » : réponse JSON 409 (et non une redirection vers « / ») pour un utilisateur déjà
        // connecté qui appelle connexion / inscription depuis la SPA (App\Http\Middleware\RedirectIfAuthenticated).
        $middleware->alias(['guest' => RedirectIfAuthenticated::class]);

        // Invité sur une page web (lien de vérification d'e-mail) : renvoyé vers la connexion de la SPA.
        $middleware->redirectGuestsTo(fn () => rtrim((string) config('app.frontend_url'), '/').'/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Format d'erreur unique de l'API : { "message": "...", "errors": {} } (la validation garde son format 422).
        $json = fn (string $message, int $status, array $headers = []) => response()->json(
            ['message' => $message, 'errors' => (object) []],
            $status,
            $headers,
        );
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($json, $isApi) {
            return $isApi($request) ? $json('Vous devez être connecté.', 401) : null;
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) use ($json, $isApi) {
            if (! $isApi($request)) {
                return null;
            }
            // Refus « en 404 » des Policies (protection IDOR) : même réponse qu'une ressource inexistante.
            $status = $e instanceof AuthorizationException && $e->hasStatus() ? $e->status() : 403;

            return $status === 404
                ? $json('Ressource introuvable.', 404)
                : $json('Action non autorisée.', 403);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($json, $isApi) {
            return $isApi($request) ? $json('Ressource introuvable.', 404) : null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($json, $isApi) {
            return $isApi($request)
                ? $json('Trop de tentatives. Réessayez dans quelques instants.', 429, $e->getHeaders())
                : null;
        });

        $exceptions->render(function (TokenMismatchException $e, Request $request) use ($json, $isApi) {
            return $isApi($request) ? $json('Session expirée. Rechargez la page.', 419) : null;
        });

        // Autres erreurs HTTP : message générique (aucun détail interne exposé).
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($json, $isApi) {
            if (! $isApi($request)) {
                return null;
            }
            $status = $e->getStatusCode();
            $message = match (true) {
                $status === 403 && str_contains($e->getMessage(), 'not verified') => 'Vérifiez votre adresse e-mail pour accéder à cette fonctionnalité.',
                $status === 403 => 'Action non autorisée.',
                $status === 405 => 'Méthode non autorisée.',
                $status === 409 => $e->getMessage() ?: 'Conflit.',
                $status === 419 => 'Session expirée. Rechargez la page.',
                $status >= 500 => 'Une erreur est survenue. Réessayez plus tard.',
                default => $e->getMessage() ?: 'Requête invalide.',
            };

            return $json($message, $status, $e->getHeaders());
        });

        // Jamais de secret dans les journaux d'exception.
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'token']);
    })->create();
