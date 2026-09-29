<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un corps annoncé en JSON mais illisible est une requête invalide (400), et non une
 * requête vide qui produirait des erreurs de validation trompeuses (422).
 */
class RejectMalformedJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $content = $request->getContent();

        if ($request->isJson() && $content !== '') {
            json_decode($content);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    'message' => 'Le corps de la requête n\'est pas un JSON valide.',
                    'errors' => (object) [],
                ], 400);
            }
        }

        return $next($request);
    }
}
