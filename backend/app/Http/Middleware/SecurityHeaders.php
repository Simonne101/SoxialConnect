<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** En-têtes de sécurité (docs/security.md §11). HSTS uniquement hors environnement local. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // L'API ne sert que du JSON : aucune ressource active ne doit pouvoir s'exécuter.
        if ($request->is('api/*')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
            $headers->set('Cache-Control', 'no-store, private');
        }

        if (app()->environment('production')) {
            $headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
        }

        return $response;
    }
}
