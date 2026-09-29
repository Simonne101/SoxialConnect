<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifiant de corrélation : ajouté au contexte des journaux (Context), aux entrées
 * d'audit et à la réponse (X-Request-Id). Un identifiant client n'est repris que s'il est un UUID.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $id = Str::isUuid($incoming) ? $incoming : (string) Str::uuid7();
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
