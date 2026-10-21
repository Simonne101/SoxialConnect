<?php

namespace App\Http\Controllers\OAuth;

use App\Domain\Quota\QuotaExceeded;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\OAuth\OAuthStateStore;
use App\Domain\Video\Clipper\ClipperConflict;
use App\Domain\Video\Clipper\ClipperService;
use App\Domain\Video\Enums\VideoProjectStatus;
use App\Domain\Video\Sources\DataPortability\DataPortabilityConsents;
use App\Domain\Video\Sources\DataPortability\DataPortabilityOAuth;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\VideoProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Retour de Google après le consentement Data Portability (ADR-054), route web (session de
 * l'utilisateur). Ordre : utilisateur connecté → state (usage unique, émis pour CET utilisateur, avec
 * le projet et le compte YouTube) → erreur Google → échange du code (PKCE) → scopes accordés →
 * compte YouTube propriétaire encore connecté → jeton chiffré → reprise de l'acquisition du projet.
 * Le navigateur ne reçoit qu'un code de résultat, jamais de jeton ni de message technique.
 */
class DataPortabilityCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        OAuthStateStore $states,
        DataPortabilityOAuth $oauth,
        DataPortabilityConsents $consents,
        ClipperService $clipper,
    ): RedirectResponse {
        $user = $request->user('web');
        if ($user === null) {
            return redirect()->away($this->frontend('/login'));
        }
        $issued = $states->consumeWithContext($request->query('state'), DataPortabilityOAuth::PROVIDER, $user);
        if ($issued === null) {
            return $this->back(null, 'error', 'INVALID_STATE');
        }
        $projectId = $issued['context']['project_id'] ?? null;
        if ($request->filled('error')) {
            return $this->back($projectId, 'error', $request->query('error') === 'access_denied' ? 'ACCESS_DENIED' : 'OAUTH_FAILED');
        }
        $code = $request->query('code');
        if (! is_string($code) || $code === '' || strlen($code) > 1024) {
            return $this->back($projectId, 'error', 'OAUTH_FAILED');
        }
        if ($user->isSuspended()) {
            return $this->back($projectId, 'error', 'FORBIDDEN');
        }
        $account = SocialAccount::query()->where('user_id', $user->getKey())->whereKey($issued['context']['account_id'] ?? null)->first();
        if ($account === null || ! $account->isConnected()) {
            return $this->back($projectId, 'error', 'ACCOUNT_DISCONNECTED');
        }

        try {
            $tokens = $oauth->exchangeCode($code, $issued['verifier']);
            if ($oauth->grantedResources($tokens->scopes) === []) {
                // Aucun groupe de vidéos accordé sur l'écran Google : rien n'est enregistré.
                $oauth->revoke($tokens->refreshToken ?? $tokens->accessToken);

                return $this->back($projectId, 'error', 'SCOPE_DENIED');
            }
            $consents->store($account, $tokens);
        } catch (OAuthException $e) {
            Log::warning('oauth.data_portability.callback_failed', ['user_id' => $user->getKey(), 'error_code' => $e->errorCode]);

            return $this->back($projectId, 'error', $e->errorCode);
        }

        // Reprise de l'acquisition du projet d'origine (jamais d'un projet d'un autre utilisateur ni annulé).
        $project = VideoProject::query()->where('user_id', $user->getKey())->whereKey($projectId)->first();
        if ($project !== null && $project->status !== VideoProjectStatus::Cancelled) {
            try {
                $clipper->retryAcquisition($project);
            } catch (ClipperConflict) {
                // Source déjà prête : rien à relancer.
            } catch (QuotaExceeded $e) {
                return $this->back($projectId, 'error', $e->errorCode); // quota de clipping atteint (ADR-071)
            }
        }

        return $this->back($projectId, 'connected');
    }

    private function back(?string $projectId, string $status, ?string $code = null): RedirectResponse
    {
        $path = $projectId !== null && preg_match('/^[0-9a-f-]{36}$/i', $projectId) ? "/studio/clipper/{$projectId}" : '/studio/clipper';

        return redirect()->away($this->frontend($path.'?'.http_build_query(array_filter(['portability' => $status, 'code' => $code]))));
    }

    private function frontend(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
