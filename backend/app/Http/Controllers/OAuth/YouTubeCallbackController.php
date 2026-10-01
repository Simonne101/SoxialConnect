<?php

namespace App\Http\Controllers\OAuth;

use App\Domain\Social\Actions\ConnectYouTubeAccount;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\OAuth\OAuthStateStore;
use App\Domain\Social\OAuth\YouTubeOAuthProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Retour de Google après le consentement (route web : la session de l'utilisateur est
 * disponible). Ordre : utilisateur connecté → state (usage unique, émis pour CET utilisateur)
 * → erreur Google → échange du code (PKCE) → scopes → chaîne → compte + tokens chiffrés.
 * Le navigateur ne reçoit qu'un code de résultat, jamais de token ni de message technique.
 */
class YouTubeCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        OAuthStateStore $states,
        YouTubeOAuthProvider $oauth,
        ConnectYouTubeAccount $connect,
    ): RedirectResponse {
        $user = $request->user('web');
        if ($user === null) {
            return redirect()->away($this->frontend('/login'));
        }

        $verifier = $states->consume($request->query('state'), YouTubeOAuthProvider::PROVIDER, $user);
        if ($verifier === null) {
            return $this->back('error', 'INVALID_STATE');
        }
        if ($request->filled('error')) {
            return $this->back('error', $request->query('error') === 'access_denied' ? 'ACCESS_DENIED' : 'OAUTH_FAILED');
        }
        $code = $request->query('code');
        if (! is_string($code) || $code === '' || strlen($code) > 1024) {
            return $this->back('error', 'OAUTH_FAILED');
        }
        if ($user->isSuspended()) {
            return $this->back('error', 'FORBIDDEN');
        }

        try {
            $tokens = $oauth->exchangeCode($code, $verifier);
            if (! in_array(YouTubeOAuthProvider::SCOPES[0], $tokens->scopes, true)) {
                // Mise en ligne décochée sur l'écran Google : rien n'est enregistré.
                $oauth->revoke($tokens->refreshToken ?? $tokens->accessToken);

                return $this->back('error', 'SCOPE_DENIED');
            }
            $channel = $oauth->fetchChannel($tokens->accessToken);
            $connect->handle($user, $tokens, $channel);
        } catch (OAuthException $e) {
            Log::warning('oauth.youtube.callback_failed', ['user_id' => $user->getKey(), 'error_code' => $e->errorCode]);

            return $this->back('error', $e->errorCode);
        }

        return $this->back('connected');
    }

    private function back(string $status, ?string $code = null): RedirectResponse
    {
        return redirect()->away($this->frontend('/social-accounts?'.http_build_query(array_filter([
            'oauth' => YouTubeOAuthProvider::PROVIDER,
            'status' => $status,
            'code' => $code,
        ]))));
    }

    private function frontend(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
