<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Social\Actions\DisconnectSocialAccount;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\OAuth\OAuthStateStore;
use App\Domain\Social\OAuth\TikTokOAuthProvider;
use App\Domain\Social\OAuth\YouTubeOAuthProvider;
use App\Domain\Social\TikTok\TikTokApiException;
use App\Domain\Social\TikTok\TikTokCreatorInfo;
use App\Domain\Social\Tokens\TikTokTokenManager;
use App\Domain\Social\Tokens\YouTubeTokenManager;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialAccountResource;
use App\Http\Resources\SocialPlatformResource;
use App\Models\SocialAccount;
use App\Models\SocialPlatform;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Plateformes de référence, comptes de l'utilisateur, et connexion OAuth officielle
 * (YouTube, TikTok). Aucun token n'est jamais renvoyé au frontend.
 */
class SocialController extends Controller
{
    public function platforms(): JsonResponse
    {
        return ApiResponse::ok(SocialPlatformResource::collection(SocialPlatform::query()->orderBy('name')->get())->resolve());
    }

    public function accounts(Request $request): JsonResponse
    {
        $accounts = SocialAccount::query()->ownedBy($request->user())->with('platform')->orderBy('created_at')->get();

        return ApiResponse::ok(SocialAccountResource::collection($accounts)->resolve());
    }

    /**
     * POST /social/{provider}/connect : démarre la connexion OAuth officielle du réseau demandé
     * (route limitée à youtube|tiktok). Réponse : l'URL de consentement vers laquelle la SPA
     * redirige le navigateur ; le retour arrive sur le callback web du réseau.
     */
    public function connect(Request $request, string $provider, OAuthStateStore $states): JsonResponse
    {
        return match ($provider) {
            YouTubeOAuthProvider::PROVIDER => $this->connectYouTube($request, $states, app(YouTubeOAuthProvider::class)),
            TikTokOAuthProvider::PROVIDER => $this->connectTikTok($request, $states, app(TikTokOAuthProvider::class)),
        };
    }

    /**
     * Démarre la connexion YouTube : state (usage unique, 10 min, lié à l'utilisateur) + PKCE,
     * puis URL de consentement Google. La SPA y redirige le navigateur ; le retour arrive sur
     * le callback web (routes/web.php).
     */
    public function connectYouTube(Request $request, OAuthStateStore $states, YouTubeOAuthProvider $oauth): JsonResponse
    {
        Gate::authorize('create', SocialAccount::class);
        if (! $oauth->isConfigured()) {
            return ApiResponse::error(OAuthException::notConfigured()->getMessage(), 409);
        }

        $issued = $states->issue($request->user(), YouTubeOAuthProvider::PROVIDER);

        return ApiResponse::ok(['authorization_url' => $oauth->authorizationUrl($issued['state'], $issued['verifier'])]);
    }

    /**
     * Démarre la connexion TikTok (Login Kit Web) : state à usage unique lié à l'utilisateur,
     * puis URL de consentement TikTok (sans PKCE : réservé par TikTok au mobile et au bureau).
     */
    public function connectTikTok(Request $request, OAuthStateStore $states, TikTokOAuthProvider $oauth): JsonResponse
    {
        Gate::authorize('create', SocialAccount::class);
        if (! $oauth->isConfigured()) {
            return ApiResponse::error($oauth->notConfigured()->getMessage(), 409);
        }

        $issued = $states->issue($request->user(), TikTokOAuthProvider::PROVIDER);

        return ApiResponse::ok(['authorization_url' => $oauth->authorizationUrl($issued['state'])]);
    }

    /** Relit le nom public du compte (YouTube, TikTok). */
    public function sync(SocialAccount $account): JsonResponse
    {
        Gate::authorize('update', $account);

        return match ($account->platform?->key) {
            YouTubeOAuthProvider::PROVIDER => $this->syncYouTube($account, app(YouTubeTokenManager::class), app(YouTubeOAuthProvider::class)),
            TikTokOAuthProvider::PROVIDER => $this->syncTikTok($account, app(TikTokTokenManager::class), app(TikTokOAuthProvider::class)),
            default => ApiResponse::error('La synchronisation de ce réseau sera disponible avec son intégration officielle.', 409),
        };
    }

    /**
     * Options de publication TikTok du créateur (confidentialités autorisées, interactions,
     * durée maximale), à afficher dans le Composer juste avant la publication. Aucun token renvoyé.
     */
    public function tiktokCreatorInfo(SocialAccount $account, TikTokCreatorInfo $creatorInfo): JsonResponse
    {
        Gate::authorize('view', $account);
        if ($account->platform?->key !== TikTokOAuthProvider::PROVIDER) {
            return ApiResponse::error('Ce compte n\'est pas un compte TikTok.', 404);
        }

        try {
            $info = $creatorInfo->fetch($account);
        } catch (OAuthException|TikTokApiException $e) {
            return ApiResponse::error(str_replace(" Rien n'a été publié.", '', $e->getMessage()), $e->transient ? 503 : 409);
        }

        return ApiResponse::ok($info);
    }

    /** Déconnexion : révocation chez le réseau (au mieux) puis effacement des tokens chiffrés. */
    public function disconnect(Request $request, SocialAccount $account, DisconnectSocialAccount $action): JsonResponse
    {
        Gate::authorize('delete', $account);
        $account = $action->handle($request->user(), $account->load('platform'));

        return ApiResponse::ok((new SocialAccountResource($account))->resolve(), 'Compte déconnecté.');
    }

    private function syncYouTube(SocialAccount $account, YouTubeTokenManager $tokens, YouTubeOAuthProvider $oauth): JsonResponse
    {
        try {
            $channel = $oauth->fetchChannel($tokens->accessTokenFor($account));
        } catch (OAuthException $e) {
            return ApiResponse::error($e->getMessage(), $e->transient ? 503 : 409);
        }
        if ($channel['id'] !== $account->external_account_id) {
            return ApiResponse::error('L\'autorisation correspond désormais à une autre chaîne YouTube. Reconnectez le compte.', 409);
        }
        $account->fill(['display_name' => $channel['title'], 'username' => $channel['custom_url']]);
        $account->forceFill(['last_synced_at' => now()])->save();

        return ApiResponse::ok((new SocialAccountResource($account->load('platform')))->resolve(), 'Compte synchronisé.');
    }

    private function syncTikTok(SocialAccount $account, TikTokTokenManager $tokens, TikTokOAuthProvider $oauth): JsonResponse
    {
        try {
            $profile = $oauth->fetchUser($tokens->accessTokenFor($account));
        } catch (OAuthException $e) {
            return ApiResponse::error($e->getMessage(), $e->transient ? 503 : 409);
        }
        if (! hash_equals((string) $account->external_account_id, $profile['open_id'])) {
            return ApiResponse::error('L\'autorisation correspond désormais à un autre compte TikTok. Reconnectez le compte.', 409);
        }
        $account->fill(['display_name' => $profile['display_name']]);
        $account->forceFill(['last_synced_at' => now()])->save();

        return ApiResponse::ok((new SocialAccountResource($account->load('platform')))->resolve(), 'Compte synchronisé.');
    }
}
