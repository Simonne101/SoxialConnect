<?php

namespace App\Http\Controllers\OAuth;

use App\Domain\Social\Actions\ConnectLinkedInAccount;
use App\Domain\Social\OAuth\LinkedInOAuthProvider;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\OAuth\OAuthStateStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Retour de LinkedIn après le consentement (GET /oauth/linkedin/callback, route web : session de
 * l'utilisateur disponible). Ordre : utilisateur connecté → state (usage unique, émis pour CET
 * utilisateur, 10 min) → erreur LinkedIn → échange du code → permission w_member_social →
 * identité (userinfo) → compte + token chiffré + permissions.
 * Le navigateur ne reçoit qu'un code de résultat, jamais de token ni de message technique.
 */
class LinkedInCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        OAuthStateStore $states,
        LinkedInOAuthProvider $oauth,
        ConnectLinkedInAccount $connect,
    ): RedirectResponse {
        $user = $request->user('web');
        if ($user === null) {
            return redirect()->away($this->frontend('/login'));
        }

        // State d'abord (protection CSRF), même quand LinkedIn renvoie une erreur.
        if ($states->consume($request->query('state'), LinkedInOAuthProvider::PROVIDER, $user) === null) {
            return $this->back('error', 'INVALID_STATE');
        }
        if ($request->filled('error')) {
            $cancelled = in_array($request->query('error'), ['user_cancelled_login', 'user_cancelled_authorize'], true);

            return $this->back('error', $cancelled ? 'ACCESS_DENIED' : 'OAUTH_FAILED');
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
            if ($tokens->scopes !== [] && ! in_array(LinkedInOAuthProvider::PUBLISH_SCOPE, $tokens->scopes, true)) {
                // Publication non accordée : rien n'est enregistré.
                return $this->back('error', 'PUBLISH_SCOPE_DENIED');
            }
            $member = $oauth->fetchMember($tokens->accessToken);
            $connect->handle($user, $tokens, $member);
        } catch (OAuthException $e) {
            Log::warning('oauth.linkedin.callback_failed', ['user_id' => $user->getKey(), 'error_code' => $e->errorCode]);

            return $this->back('error', $e->errorCode);
        }

        return $this->back('connected');
    }

    private function back(string $status, ?string $code = null): RedirectResponse
    {
        return redirect()->away($this->frontend('/social-accounts?'.http_build_query(array_filter([
            'oauth' => LinkedInOAuthProvider::PROVIDER,
            'status' => $status,
            'code' => $code,
        ]))));
    }

    private function frontend(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
