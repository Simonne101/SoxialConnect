<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Taille maximale des corps JSON de l'API (étape 8, lot 7 — docs/security.md §11) : 413 au-delà de
 * config('security.max_json_kb'), avant toute lecture du corps quand Content-Length l'annonce, sinon après mesure.
 * Les imports de fichiers (multipart) ont leurs propres limites (Nginx, PHP, médias) et ne sont pas concernés.
 */
class LimitJsonPayload
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isJson()) {
            $limit = max(1, (int) config('security.max_json_kb', 1024)) * 1024;
            $declared = $request->headers->get('Content-Length');
            $size = is_numeric($declared) ? (int) $declared : strlen((string) $request->getContent());
            if ($size > $limit) {
                return response()->json([
                    'message' => 'Requête trop volumineuse.',
                    'errors' => (object) [],
                ], 413);
            }
        }

        return $next($request);
    }
}
