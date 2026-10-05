<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated as BaseRedirectIfAuthenticated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware « guest » (connexion, inscription, mot de passe oublié) adapté à l'API de la SPA.
 *
 * Le middleware de Laravel redirige toujours un utilisateur déjà connecté vers « / ». Pour une
 * requête JSON, cette redirection est suivie par le navigateur jusqu'au serveur du site (Vite),
 * qui répond 404 : l'utilisateur voyait « erreur 404 » au lieu d'une explication. Une requête
 * JSON reçoit donc une vraie réponse d'API (409) ; le comportement web reste celui de Laravel.
 */
class RedirectIfAuthenticated extends BaseRedirectIfAuthenticated
{
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        if ($request->expectsJson()) {
            foreach (empty($guards) ? [null] : $guards as $guard) {
                if (Auth::guard($guard)->check()) {
                    return response()->json([
                        'message' => 'Vous êtes déjà connecté. Déconnectez-vous pour utiliser un autre compte.',
                        'errors' => (object) [],
                    ], Response::HTTP_CONFLICT);
                }
            }
        }

        return parent::handle($request, $next, ...$guards);
    }
}
