<?php

namespace App\Domain\Social\OAuth;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * TikTok Login Kit (Web) : flux « authorization code » serveur, refresh, profil, révocation.
 * Endpoints officiels uniquement (developers.tiktok.com — Login Kit for Web, Manage User Access
 * Tokens, Get User Info). PKCE non utilisé : la documentation le réserve aux applications mobiles
 * et de bureau. Ne connaît ni la base de données ni le moteur de publication.
 */
final class TikTokOAuthProvider
{
    public const PROVIDER = 'tiktok';

    /**
     * Scopes minimaux (docs/publishing.md §12) :
     * - user.info.basic : identifiant (open_id) et nom affiché du compte ;
     * - video.publish   : publication directe (Content Posting API, Direct Post).
     */
    public const SCOPES = ['user.info.basic', 'video.publish'];

    public const PUBLISH_SCOPE = 'video.publish';

    private const AUTHORIZE_URL = 'https://www.tiktok.com/v2/auth/authorize/';

    private const TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';

    private const REVOKE_URL = 'https://open.tiktokapis.com/v2/oauth/revoke/';

    private const USER_INFO_URL = 'https://open.tiktokapis.com/v2/user/info/';

    public function isConfigured(): bool
    {
        return filled(config('services.tiktok.client_key'))
            && filled(config('services.tiktok.client_secret'))
            && filled(config('services.tiktok.redirect'));
    }

    public function authorizationUrl(string $state): string
    {
        $this->assertConfigured();

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_key' => config('services.tiktok.client_key'),
            'scope' => implode(',', self::SCOPES),
            'redirect_uri' => config('services.tiktok.redirect'),
            'state' => $state,
            'response_type' => 'code',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code): OAuthTokens
    {
        $this->assertConfigured();

        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.tiktok.redirect'),
        ]);
    }

    /** Le refresh token renvoyé peut différer de l'ancien : l'appelant DOIT enregistrer le nouveau. */
    public function refresh(string $refreshToken): OAuthTokens
    {
        $this->assertConfigured();

        return $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /**
     * Profil du compte autorisé (scope user.info.basic).
     *
     * @return array{open_id: string, display_name: string}
     */
    public function fetchUser(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)->acceptJson()->timeout(20)
                ->get(self::USER_INFO_URL, ['fields' => 'open_id,display_name']);
        } catch (ConnectionException) {
            throw $this->network();
        }

        $error = (string) ($response->json('error.code') ?? '');
        if ($response->status() === 401 || $error === 'access_token_invalid') {
            throw $this->reconnect('TOKEN_INVALID');
        }
        if (! $response->successful() || ($error !== '' && $error !== 'ok')) {
            throw new OAuthException('PROFILE_LOOKUP_FAILED', 'Le profil TikTok n\'a pas pu être lu. Réessayez.', transient: $response->serverError());
        }

        $user = $response->json('data.user');
        if (! is_array($user) || ! is_string($user['open_id'] ?? null) || $user['open_id'] === '') {
            throw new OAuthException('INVALID_PROFILE_RESPONSE', 'Réponse inattendue de TikTok. Réessayez la connexion.');
        }

        return [
            'open_id' => $user['open_id'],
            'display_name' => (string) ($user['display_name'] ?? 'Compte TikTok'),
        ];
    }

    /** Révocation côté TikTok (au mieux : une panne n'empêche pas la déconnexion locale). */
    public function revoke(string $accessToken): bool
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(10)->post(self::REVOKE_URL, [
                'client_key' => config('services.tiktok.client_key'),
                'client_secret' => config('services.tiktok.client_secret'),
                'token' => $accessToken,
            ]);

            return $response->successful() && blank($response->json('error'));
        } catch (ConnectionException) {
            return false;
        }
    }

    /** @param array<string, string> $params */
    private function tokenRequest(array $params): OAuthTokens
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->post(self::TOKEN_URL, $params + [
                'client_key' => config('services.tiktok.client_key'),
                'client_secret' => config('services.tiktok.client_secret'),
            ]);
        } catch (ConnectionException) {
            throw $this->network();
        }

        // TikTok peut renvoyer une erreur dans le corps : { error, error_description, log_id }.
        if (! $response->successful() || filled($response->json('error'))) {
            throw $this->tokenError($response);
        }

        $access = $response->json('access_token');
        $openId = $response->json('open_id');
        if (! is_string($access) || $access === '' || ! is_string($openId) || $openId === '') {
            throw new OAuthException('INVALID_TOKEN_RESPONSE', 'Réponse inattendue de TikTok. Réessayez la connexion.');
        }
        $expiresIn = $response->json('expires_in');
        $refreshExpiresIn = $response->json('refresh_expires_in');
        $scope = $response->json('scope');

        return new OAuthTokens(
            accessToken: $access,
            refreshToken: is_string($response->json('refresh_token')) ? $response->json('refresh_token') : null,
            expiresAt: is_numeric($expiresIn) ? CarbonImmutable::now()->addSeconds((int) $expiresIn) : null,
            scopes: is_string($scope) ? array_values(array_filter(array_map('trim', explode(',', $scope)))) : [],
            tokenType: is_string($response->json('token_type')) ? $response->json('token_type') : 'Bearer',
            providerUserId: $openId,
            refreshExpiresAt: is_numeric($refreshExpiresIn) ? CarbonImmutable::now()->addSeconds((int) $refreshExpiresIn) : null,
        );
    }

    private function tokenError(Response $response): OAuthException
    {
        // Seul le code d'erreur est conservé : jamais le corps complet (log_id, description).
        $error = (string) ($response->json('error') ?: 'http_'.$response->status());

        return match (true) {
            $error === 'invalid_grant' => $this->reconnect('TOKEN_REVOKED'),
            $error === 'invalid_client', $error === 'unauthorized_client' => new OAuthException('OAUTH_CLIENT_INVALID', 'La configuration TikTok du serveur est invalide (client key ou secret). Contactez l\'administrateur.'),
            $response->serverError() => $this->network(),
            default => new OAuthException('OAUTH_FAILED', 'TikTok a refusé l\'autorisation. Réessayez la connexion.'),
        };
    }

    public function notConfigured(): OAuthException
    {
        return new OAuthException('NOT_CONFIGURED', 'La connexion TikTok n\'est pas configurée sur ce serveur (identifiants TikTok absents).');
    }

    public function reconnect(string $code): OAuthException
    {
        return new OAuthException($code, 'L\'autorisation TikTok a expiré ou a été retirée. Reconnectez le compte puis réessayez.', requiresReconnect: true);
    }

    private function network(): OAuthException
    {
        return new OAuthException('NETWORK_ERROR', 'TikTok est momentanément injoignable. Réessayez dans un instant.', transient: true);
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw $this->notConfigured();
        }
    }
}
