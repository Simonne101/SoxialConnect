<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Social\Actions\ConnectTelegramChat;
use App\Domain\Social\Actions\DisconnectSocialAccount;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Domain\Social\OAuth\LinkedInOAuthProvider;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\OAuth\OAuthStateStore;
use App\Domain\Social\OAuth\TikTokOAuthProvider;
use App\Domain\Social\OAuth\XOAuthProvider;
use App\Domain\Social\OAuth\YouTubeOAuthProvider;
use App\Domain\Social\Telegram\TelegramApiException;
use App\Domain\Social\Telegram\TelegramBotClient;
use App\Domain\Social\Telegram\TelegramChatInspector;
use App\Domain\Social\Telegram\TelegramLinkException;
use App\Domain\Social\Telegram\TelegramLinkStore;
use App\Domain\Social\TikTok\TikTokApiException;
use App\Domain\Social\TikTok\TikTokCreatorInfo;
use App\Domain\Social\Tokens\LinkedInTokenManager;
use App\Domain\Social\Tokens\TikTokTokenManager;
use App\Domain\Social\Tokens\XTokenManager;
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
 * (YouTube, TikTok, LinkedIn, X). Aucun token n'est jamais renvoyé au frontend.
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
     * (route limitée à youtube|tiktok|linkedin|x). Réponse : l'URL de consentement vers laquelle la SPA
     * redirige le navigateur ; le retour arrive sur le callback web du réseau.
     */
    public function connect(Request $request, string $provider, OAuthStateStore $states): JsonResponse
    {
        return match ($provider) {
            YouTubeOAuthProvider::PROVIDER => $this->connectYouTube($request, $states, app(YouTubeOAuthProvider::class)),
            TikTokOAuthProvider::PROVIDER => $this->connectTikTok($request, $states, app(TikTokOAuthProvider::class)),
            LinkedInOAuthProvider::PROVIDER => $this->connectLinkedIn($request, $states, app(LinkedInOAuthProvider::class)),
            XOAuthProvider::PROVIDER => $this->connectX($request, $states, app(XOAuthProvider::class)),
        };
    }

    /**
     * Démarre la connexion LinkedIn (authorization code) : state à usage unique lié à
     * l'utilisateur, puis URL de consentement LinkedIn (scopes openid, profile, email, w_member_social).
     */
    public function connectLinkedIn(Request $request, OAuthStateStore $states, LinkedInOAuthProvider $oauth): JsonResponse
    {
        Gate::authorize('create', SocialAccount::class);
        if (! $oauth->isConfigured()) {
            return ApiResponse::error($oauth->notConfigured()->getMessage(), 409);
        }

        $issued = $states->issue($request->user(), LinkedInOAuthProvider::PROVIDER);

        return ApiResponse::ok(['authorization_url' => $oauth->authorizationUrl($issued['state'])]);
    }

    /**
     * Démarre la connexion X : state (usage unique, 10 min, lié à l'utilisateur) + PKCE S256, puis
     * URL de consentement X (scopes users.read, tweet.read, tweet.write, offline.access).
     */
    public function connectX(Request $request, OAuthStateStore $states, XOAuthProvider $oauth): JsonResponse
    {
        Gate::authorize('create', SocialAccount::class);
        if (! $oauth->isConfigured()) {
            return ApiResponse::error($oauth->notConfigured()->getMessage(), 409);
        }

        $issued = $states->issue($request->user(), XOAuthProvider::PROVIDER);

        return ApiResponse::ok(['authorization_url' => $oauth->authorizationUrl($issued['state'], $issued['verifier'])]);
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

    /**
     * POST /social/telegram/link : lien de liaison d'une chaîne ou d'un groupe Telegram
     * (t.me/<bot>?start=<code>, code à usage unique valable 10 min, lié à l'utilisateur). La suite
     * se passe dans Telegram ; le webhook enregistre la destination (TelegramUpdateHandler).
     */
    public function telegramLink(Request $request, TelegramBotClient $telegram, TelegramLinkStore $links): JsonResponse
    {
        Gate::authorize('create', SocialAccount::class);
        if (! $telegram->canLinkChats()) {
            return ApiResponse::error('La connexion Telegram n\'est pas encore configurée sur le serveur.', 409);
        }

        $code = $links->issueCode($request->user());

        return ApiResponse::ok([
            'link_url' => 'https://t.me/'.rawurlencode($telegram->botUsername()).'?start='.$code,
            'bot_username' => $telegram->botUsername(),
            'expires_at' => now()->addSeconds(TelegramLinkStore::TTL_SECONDS)->toIso8601String(),
        ]);
    }

    /** Relit le nom public du compte (YouTube, TikTok) ou le titre et les droits du bot (Telegram). */
    public function sync(SocialAccount $account): JsonResponse
    {
        Gate::authorize('update', $account);

        return match ($account->platform?->key) {
            YouTubeOAuthProvider::PROVIDER => $this->syncYouTube($account, app(YouTubeTokenManager::class), app(YouTubeOAuthProvider::class)),
            TikTokOAuthProvider::PROVIDER => $this->syncTikTok($account, app(TikTokTokenManager::class), app(TikTokOAuthProvider::class)),
            ConnectTelegramChat::PROVIDER => $this->syncTelegram($account, app(TelegramBotClient::class), app(TelegramChatInspector::class)),
            LinkedInOAuthProvider::PROVIDER => $this->syncLinkedIn($account, app(LinkedInTokenManager::class), app(LinkedInOAuthProvider::class)),
            XOAuthProvider::PROVIDER => $this->syncX($account, app(XTokenManager::class), app(XOAuthProvider::class)),
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

    /**
     * Telegram : titre, nom public et droits RÉELS du bot relus auprès de Telegram. Droits
     * perdus → destination « à reconnecter » ; droits rétablis → de nouveau connectée.
     * Une destination déconnectée par l'utilisateur n'est jamais réactivée ici.
     */
    private function syncTelegram(SocialAccount $account, TelegramBotClient $telegram, TelegramChatInspector $inspector): JsonResponse
    {
        if (! $telegram->isConfigured()) {
            return ApiResponse::error('La connexion Telegram n\'est pas encore configurée sur le serveur.', 409);
        }
        if ($account->status === SocialAccountStatus::Revoked) {
            return ApiResponse::error('Cette destination a été déconnectée. Reconnectez-la depuis Telegram.', 409);
        }

        try {
            $chat = $inspector->inspect($account->external_account_id);
        } catch (TelegramLinkException $e) {
            $account->forceFill([
                'status' => SocialAccountStatus::Error,
                'status_reason' => $e->getMessage(),
                'last_error_at' => now(),
                'last_synced_at' => now(),
            ])->save();

            return ApiResponse::ok((new SocialAccountResource($account->load('platform')))->resolve(), $e->getMessage());
        } catch (TelegramApiException) {
            return ApiResponse::error('Telegram est momentanément injoignable. Réessayez dans un instant.', 503);
        }

        $account->fill([
            'display_name' => $chat['title'],
            'username' => $chat['username'],
            'metadata' => array_merge($account->metadata ?? [], ['chat_type' => $chat['type']]),
        ]);
        $account->forceFill(['status' => SocialAccountStatus::Connected, 'status_reason' => null, 'last_synced_at' => now()])->save();

        return ApiResponse::ok((new SocialAccountResource($account->load('platform')))->resolve(), 'Destination synchronisée.');
    }

    /** LinkedIn : nom et e-mail relus (userinfo) ; le membre doit rester le même. */
    private function syncLinkedIn(SocialAccount $account, LinkedInTokenManager $tokens, LinkedInOAuthProvider $oauth): JsonResponse
    {
        try {
            $member = $oauth->fetchMember($tokens->accessTokenFor($account));
        } catch (OAuthException $e) {
            if ($e->requiresReconnect && $e->errorCode === 'TOKEN_INVALID') {
                $tokens->rejected($account);
            }

            return ApiResponse::error($e->getMessage(), $e->transient ? 503 : 409);
        }
        if (! hash_equals((string) $account->external_account_id, $member['sub'])) {
            return ApiResponse::error('L\'autorisation correspond désormais à un autre profil LinkedIn. Reconnectez le compte.', 409);
        }
        $account->fill(['display_name' => $member['name'], 'username' => $member['email']]);
        $account->forceFill(['last_synced_at' => now()])->save();

        return ApiResponse::ok((new SocialAccountResource($account->load('platform')))->resolve(), 'Compte synchronisé.');
    }

    /**
     * X : nom, @identifiant et photo relus (users/me) ; le compte doit rester le même. Jeton refusé
     * (401) : un seul renouvellement forcé, puis « à reconnecter » si X refuse aussi le refresh.
     */
    private function syncX(SocialAccount $account, XTokenManager $tokens, XOAuthProvider $oauth): JsonResponse
    {
        try {
            try {
                $profile = $oauth->fetchUser($tokens->accessTokenFor($account));
            } catch (OAuthException $e) {
                if ($e->errorCode !== 'TOKEN_INVALID') {
                    throw $e;
                }
                $profile = $oauth->fetchUser($tokens->accessTokenFor($account, forceRefresh: true));
            }
        } catch (OAuthException $e) {
            return ApiResponse::error($e->getMessage(), $e->transient ? 503 : 409);
        }
        if (! hash_equals((string) $account->external_account_id, $profile['id'])) {
            return ApiResponse::error('L\'autorisation correspond désormais à un autre compte X. Reconnectez le compte.', 409);
        }
        $account->fill([
            'display_name' => $profile['name'],
            'username' => '@'.$profile['username'],
            'metadata' => array_filter(array_merge($account->metadata ?? [], ['username' => $profile['username'], 'picture_url' => $profile['picture']])),
        ]);
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
