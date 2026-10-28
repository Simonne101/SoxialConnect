<?php

namespace App\Http\Controllers\OAuth;

use App\Domain\Social\Actions\ConnectInstagramAccount;
use App\Domain\Social\OAuth\InstagramOAuthProvider;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\OAuth\OAuthStateStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Retour d'Instagram après le consentement (GET /oauth/instagram/callback, route web : session de
 * l'utilisateur disponible). Ordre : utilisateur connecté → state (usage unique, émis pour CET
 * utilisateur, 10 min) → erreur Instagram → échange du code (jeton longue durée) → permissions de
 * base et de publication → profil (compte professionnel) → compte + jeton chiffré + permissions.
 * Le navigateur ne reçoit qu'un code de résultat, jamais de jeton ni de message technique.
 */
class InstagramCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        OAuthStateStore $states,
        InstagramOAuthProvider $oauth,
        ConnectInstagramAccount $connect,
    ): RedirectResponse {
        $user = $request->user('web');
        if ($user === null) {
            return redirect()->away($this->frontend('/login'));
        }

        // State d'abord (protection CSRF), même quand Instagram renvoie une erreur.
        if ($states->consume($request->query('state'), InstagramOAuthProvider::PROVIDER, $user) === null) {
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
            if ($tokens->scopes !== [] && array_diff(InstagramOAuthProvider::REQUIRED_SCOPES, $tokens->scopes) !== []) {
                // Publication non accordée : rien n'est enregistré.
                return $this->back('error', 'PUBLISH_SCOPE_DENIED');
            }
            $connect->handle($user, $tokens, $oauth->fetchProfile($tokens->accessToken));
        } catch (OAuthException $e) {
            Log::warning('oauth.instagram.callback_failed', ['user_id' => $user->getKey(), 'error_code' => $e->errorCode]);

            return $this->back('error', $e->errorCode);
        }

        return $this->back('connected');
    }

    private function back(string $status, ?string $code = null): RedirectResponse
    {
        return redirect()->away($this->frontend('/social-accounts?'.http_build_query(array_filter([
            'oauth' => InstagramOAuthProvider::PROVIDER,
            'status' => $status,
            'code' => $code,
        ]))));
    }

    private function frontend(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
