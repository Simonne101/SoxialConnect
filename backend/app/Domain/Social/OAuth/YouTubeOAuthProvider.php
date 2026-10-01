<?php

namespace App\Domain\Social\OAuth;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google OAuth 2.0 (flux « authorization code » serveur + PKCE S256) pour YouTube, et lecture
 * de la chaîne connectée (YouTube Data API v3, channels.list). Endpoints officiels Google
 * uniquement ; aucun SDK. Ne connaît ni la base de données ni le moteur de publication.
 */
final class YouTubeOAuthProvider
{
    public const PROVIDER = 'youtube';

    /**
     * Scopes minimaux (docs/publishing.md §11) :
     * - youtube.upload : mettre en ligne des vidéos (videos.insert) ;
     * - youtube.readonly : identifier la chaîne connectée (channels.list mine=true).
     */
    public const SCOPES = [
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube.readonly',
    ];

    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    private const CHANNELS_URL = 'https://www.googleapis.com/youtube/v3/channels';

    public function isConfigured(): bool
    {
        return filled(config('services.youtube.client_id'))
            && filled(config('services.youtube.client_secret'))
            && filled(config('services.youtube.redirect'));
    }

    public function authorizationUrl(string $state, string $verifier): string
    {
        $this->assertConfigured();

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('services.youtube.client_id'),
            'redirect_uri' => config('services.youtube.redirect'),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            // Refresh token (accès hors ligne) ; « consent » le redonne aussi lors d'une reconnexion.
            'access_type' => 'offline',
            'prompt' => 'consent select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $verifier): OAuthTokens
    {
        $this->assertConfigured();

        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => config('services.youtube.redirect'),
        ]);
    }

    public function refresh(string $refreshToken): OAuthTokens
    {
        $this->assertConfigured();

        return $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /**
     * Chaîne YouTube de l'utilisateur qui vient d'autoriser l'accès.
     *
     * @return array{id: string, title: string, custom_url: ?string}
     */
    public function fetchChannel(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)->acceptJson()->timeout(20)
                ->get(self::CHANNELS_URL, ['part' => 'snippet', 'mine' => 'true']);
        } catch (ConnectionException) {
            throw OAuthException::network();
        }

        if ($response->status() === 401) {
            throw OAuthException::reconnectRequired('TOKEN_INVALID');
        }
        if (! $response->successful()) {
            throw new OAuthException('CHANNEL_LOOKUP_FAILED', 'La chaîne YouTube n\'a pas pu être lue. Réessayez.', transient: $response->serverError());
        }

        $item = $response->json('items.0');
        if (! is_array($item) || ! is_string($item['id'] ?? null) || $item['id'] === '') {
            throw new OAuthException('NO_CHANNEL', 'Ce compte Google n\'a pas de chaîne YouTube. Créez une chaîne puis reconnectez-vous.');
        }

        return [
            'id' => $item['id'],
            'title' => (string) ($item['snippet']['title'] ?? 'Chaîne YouTube'),
            'custom_url' => isset($item['snippet']['customUrl']) ? (string) $item['snippet']['customUrl'] : null,
        ];
    }

    /** Révocation côté Google (au mieux : une panne n'empêche pas la déconnexion locale). */
    public function revoke(string $token): bool
    {
        try {
            return Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token])->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /** @param array<string, string> $params */
    private function tokenRequest(array $params): OAuthTokens
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->post(self::TOKEN_URL, $params + [
                'client_id' => config('services.youtube.client_id'),
                'client_secret' => config('services.youtube.client_secret'),
            ]);
        } catch (ConnectionException) {
            throw OAuthException::network();
        }

        if (! $response->successful()) {
            throw $this->tokenError($response);
        }

        $access = $response->json('access_token');
        if (! is_string($access) || $access === '') {
            throw new OAuthException('INVALID_TOKEN_RESPONSE', 'Réponse inattendue de Google. Réessayez la connexion.');
        }
        $expiresIn = $response->json('expires_in');
        $scope = $response->json('scope');

        return new OAuthTokens(
            accessToken: $access,
            refreshToken: is_string($response->json('refresh_token')) ? $response->json('refresh_token') : null,
            expiresAt: is_numeric($expiresIn) ? CarbonImmutable::now()->addSeconds((int) $expiresIn) : null,
            scopes: is_string($scope) ? array_values(array_filter(explode(' ', $scope))) : [],
            tokenType: is_string($response->json('token_type')) ? $response->json('token_type') : 'Bearer',
        );
    }

    private function tokenError(Response $response): OAuthException
    {
        // Seul le code d'erreur OAuth est conservé (jamais le corps complet de la réponse).
        $error = (string) ($response->json('error') ?? 'http_'.$response->status());

        return match (true) {
            $error === 'invalid_grant' => OAuthException::reconnectRequired('TOKEN_REVOKED'),
            $error === 'invalid_client', $error === 'unauthorized_client' => new OAuthException('OAUTH_CLIENT_INVALID', 'La configuration OAuth Google est invalide (identifiant ou secret client). Contactez l\'administrateur.'),
            $response->serverError() => OAuthException::network(),
            default => new OAuthException('OAUTH_FAILED', 'Google a refusé l\'autorisation. Réessayez la connexion.'),
        };
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw OAuthException::notConfigured();
        }
    }
}
