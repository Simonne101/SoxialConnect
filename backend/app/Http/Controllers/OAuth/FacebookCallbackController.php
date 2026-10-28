<?php

namespace App\Http\Controllers\OAuth;

use App\Domain\Social\Actions\ConnectFacebookPages;
use App\Domain\Social\OAuth\FacebookOAuthProvider;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\OAuth\OAuthStateStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Retour de Facebook après le consentement (GET /oauth/facebook/callback, route web : session de
 * l'utilisateur disponible). Ordre : utilisateur connecté → state (usage unique, émis pour CET
 * utilisateur, 10 min) → erreur Facebook → échange du code (jeton longue durée) → permissions
 * pages_show_list et pages_manage_posts → identité → Pages + jetons de Page chiffrés + permissions.
 * Le navigateur ne reçoit qu'un code de résultat, jamais de jeton ni de message technique.
 */
class FacebookCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        OAuthStateStore $states,
        FacebookOAuthProvider $oauth,
        ConnectFacebookPages $connect,
    ): RedirectResponse {
        $user = $request->user('web');
        if ($user === null) {
            return redirect()->away($this->frontend('/login'));
        }

        // State d'abord (protection CSRF), même quand Facebook renvoie une erreur.
        if ($states->consume($request->query('state'), FacebookOAuthProvider::PROVIDER, $user) === null) {
            return $this->back('error', 'INVALID_STATE');
        }
        if ($request->filled('error')) {
            return $this->back('error', $request->query('error') === 'access_denied' ? 'ACCESS_DENIED' : 'OAUTH_FAILED');
        }
        $code = $request->query('code');
        if (! is_string($code) || $code === '' || strlen($code) > 2048) {
            return $this->back('error', 'OAUTH_FAILED');
        }
        if ($user->isSuspended()) {
            return $this->back('error', 'FORBIDDEN');
        }

        try {
            $tokens = $oauth->exchangeCode($code);
            if (array_diff(FacebookOAuthProvider::REQUIRED_SCOPES, $tokens->scopes) !== []) {
                // Liste des Pages ou publication refusée : rien n'est enregistré.
                return $this->back('error', 'PUBLISH_SCOPE_DENIED');
            }
            $person = $oauth->fetchUser($tokens->accessToken);
            $accounts = $connect->handle($user, $tokens, $person, $oauth->fetchPages($tokens->accessToken));
        } catch (OAuthException $e) {
            Log::warning('oauth.facebook.callback_failed', ['user_id' => $user->getKey(), 'error_code' => $e->errorCode]);

            return $this->back('error', $e->errorCode);
        }

        return $this->back('connected', null, count($accounts));
    }

    private function back(string $status, ?string $code = null, ?int $count = null): RedirectResponse
    {
        return redirect()->away($this->frontend('/social-accounts?'.http_build_query(array_filter([
            'oauth' => FacebookOAuthProvider::PROVIDER,
            'status' => $status,
            'code' => $code,
            'count' => $count,
        ]))));
    }

    private function frontend(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
