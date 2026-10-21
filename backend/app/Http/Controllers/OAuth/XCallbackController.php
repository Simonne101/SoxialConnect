<?php

namespace App\Http\Controllers\OAuth;

use App\Domain\Social\Actions\ConnectXAccount;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\OAuth\OAuthStateStore;
use App\Domain\Social\OAuth\XOAuthProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Retour de X après le consentement (GET /oauth/x/callback, route web : session de l'utilisateur
 * disponible). Ordre : utilisateur connecté → state (usage unique, émis pour CET utilisateur,
 * 10 min ; restitue le vérificateur PKCE) → erreur X → échange du code (PKCE) → permission
 * tweet.write → identité (users/me) → compte + token chiffré + permissions.
 * Le navigateur ne reçoit qu'un code de résultat, jamais de token ni de message technique.
 */
class XCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        OAuthStateStore $states,
        XOAuthProvider $oauth,
        ConnectXAccount $connect,
    ): RedirectResponse {
        $user = $request->user('web');
        if ($user === null) {
            return redirect()->away($this->frontend('/login'));
        }

        // State d'abord (protection CSRF), même quand X renvoie une erreur.
        $verifier = $states->consume($request->query('state'), XOAuthProvider::PROVIDER, $user);
        if ($verifier === null) {
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
            $tokens = $oauth->exchangeCode($code, $verifier);
            if ($tokens->scopes !== [] && ! in_array(XOAuthProvider::PUBLISH_SCOPE, $tokens->scopes, true)) {
                // Publication non accordée : rien n'est enregistré.
                return $this->back('error', 'PUBLISH_SCOPE_DENIED');
            }
            $profile = $oauth->fetchUser($tokens->accessToken);
            $connect->handle($user, $tokens, $profile);
        } catch (OAuthException $e) {
            Log::warning('oauth.x.callback_failed', ['user_id' => $user->getKey(), 'error_code' => $e->errorCode]);

            return $this->back('error', $e->errorCode);
        }

        return $this->back('connected');
    }

    private function back(string $status, ?string $code = null): RedirectResponse
    {
        return redirect()->away($this->frontend('/social-accounts?'.http_build_query(array_filter([
            'oauth' => XOAuthProvider::PROVIDER,
            'status' => $status,
            'code' => $code,
        ]))));
    }

    private function frontend(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
