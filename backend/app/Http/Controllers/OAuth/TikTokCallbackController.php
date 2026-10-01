<?php

namespace App\Http\Controllers\OAuth;

use App\Domain\Social\Actions\ConnectTikTokAccount;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\OAuth\OAuthStateStore;
use App\Domain\Social\OAuth\TikTokOAuthProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Retour de TikTok après le consentement (route web : session de l'utilisateur disponible).
 * Ordre : utilisateur connecté → state (usage unique, émis pour CET utilisateur) → erreur TikTok
 * → échange du code → scope video.publish → profil → compte + token chiffré + permissions.
 * Le navigateur ne reçoit qu'un code de résultat, jamais de token ni de message technique.
 */
class TikTokCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        OAuthStateStore $states,
        TikTokOAuthProvider $oauth,
        ConnectTikTokAccount $connect,
    ): RedirectResponse {
        $user = $request->user('web');
        if ($user === null) {
            return redirect()->away($this->frontend('/login'));
        }

        if ($states->consume($request->query('state'), TikTokOAuthProvider::PROVIDER, $user) === null) {
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
            if (! in_array(TikTokOAuthProvider::PUBLISH_SCOPE, $tokens->scopes, true)) {
                // Publication décochée sur l'écran TikTok : rien n'est enregistré, l'accès est retiré.
                $oauth->revoke($tokens->accessToken);

                return $this->back('error', 'SCOPE_DENIED');
            }
            $profile = $oauth->fetchUser($tokens->accessToken);
            if ($tokens->providerUserId !== null && ! hash_equals($tokens->providerUserId, $profile['open_id'])) {
                $oauth->revoke($tokens->accessToken);

                return $this->back('error', 'OAUTH_FAILED');
            }
            $connect->handle($user, $tokens, $profile);
        } catch (OAuthException $e) {
            Log::warning('oauth.tiktok.callback_failed', ['user_id' => $user->getKey(), 'error_code' => $e->errorCode]);

            return $this->back('error', $e->errorCode);
        }

        return $this->back('connected');
    }

    private function back(string $status, ?string $code = null): RedirectResponse
    {
        return redirect()->away($this->frontend('/social-accounts?'.http_build_query(array_filter([
            'oauth' => TikTokOAuthProvider::PROVIDER,
            'status' => $status,
            'code' => $code,
        ]))));
    }

    private function frontend(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
