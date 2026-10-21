<?php

namespace App\Domain\Social\OAuth;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * X OAuth 2.0 « Authorization Code » avec PKCE (S256), client confidentiel.
 * Endpoints officiels (docs.x.com, resources/fundamentals/authentication/oauth-2-0) :
 * - autorisation : https://x.com/i/oauth2/authorize ;
 * - token, refresh, révocation : https://api.x.com/2/oauth2/{token,revoke}, authentification
 *   du client en HTTP Basic (client_id:client_secret) ;
 * - identité : GET https://api.x.com/2/users/me.
 *
 * Jeton d'accès valable 2 h ; refresh token fourni avec le scope offline.access, à usage unique
 * (X en renvoie un nouveau à chaque renouvellement). Ne connaît ni la base de données ni le
 * moteur de publication.
 */
final class XOAuthProvider
{
    public const PROVIDER = 'x';

    /**
     * Scopes minimaux (docs/publishing.md §15) :
     * - users.read + tweet.read : identité du compte (users/me exige les deux) ;
     * - tweet.write             : publier des posts (phase de publication) ;
     * - offline.access          : refresh token, sans quoi l'autorisation expire au bout de 2 h.
     * media.write (envoi d'images ou de vidéos) n'est pas demandé tant que l'envoi de médias
     * n'est pas implémenté : une reconnexion sera nécessaire à ce moment-là.
     */
    public const SCOPES = ['users.read', 'tweet.read', 'tweet.write', 'offline.access'];

    public const PUBLISH_SCOPE = 'tweet.write';

    private const AUTHORIZE_URL = 'https://x.com/i/oauth2/authorize';

    private const TOKEN_URL = 'https://api.x.com/2/oauth2/token';

    private const REVOKE_URL = 'https://api.x.com/2/oauth2/revoke';

    private const ME_URL = 'https://api.x.com/2/users/me';

    public function isConfigured(): bool
    {
        return filled(config('services.x.client_id'))
            && filled(config('services.x.client_secret'))
            && filled(config('services.x.redirect'));
    }

    public function authorizationUrl(string $state, string $verifier): string
    {
        $this->assertConfigured();

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.x.client_id'),
            'redirect_uri' => config('services.x.redirect'),
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $verifier): OAuthTokens
    {
        $this->assertConfigured();

        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.x.redirect'),
            'code_verifier' => $verifier,
        ], refreshing: false);
    }

    public function refresh(string $refreshToken): OAuthTokens
    {
        $this->assertConfigured();

        return $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken], refreshing: true);
    }

    /**
     * Identité du compte X autorisé.
     *
     * @return array{id: string, username: string, name: string, picture: ?string}
     */
    public function fetchUser(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)->acceptJson()->timeout(20)
                ->get(self::ME_URL, ['user.fields' => 'profile_image_url']);
        } catch (ConnectionException) {
            throw $this->network();
        }

        if ($response->status() === 401) {
            throw $this->reconnect('TOKEN_INVALID');
        }
        if (in_array($response->status(), [402, 403], true)) {
            throw $this->apiAccessDenied();
        }
        if ($response->status() === 429) {
            throw $this->rateLimited();
        }
        if (! $response->successful()) {
            throw new OAuthException('PROFILE_LOOKUP_FAILED', 'Le profil X n\'a pas pu être lu. Réessayez.', transient: $response->serverError());
        }

        $id = $response->json('data.id');
        $username = $response->json('data.username');
        if (! is_string($id) || $id === '' || ! is_string($username) || $username === '') {
            throw new OAuthException('INVALID_PROFILE_RESPONSE', 'Réponse inattendue de X. Réessayez la connexion.');
        }
        $name = trim((string) ($response->json('data.name') ?? ''));

        return [
            'id' => $id,
            'username' => $username,
            'name' => $name !== '' ? $name : '@'.$username,
            'picture' => is_string($response->json('data.profile_image_url')) ? $response->json('data.profile_image_url') : null,
        ];
    }

    /** Révocation côté X (au mieux : une panne n'empêche pas la déconnexion locale). */
    public function revoke(string $token, string $tokenTypeHint): bool
    {
        try {
            $response = $this->client()->timeout(10)->post(self::REVOKE_URL, [
                'token' => $token,
                'token_type_hint' => $tokenTypeHint,
            ]);

            return $response->successful() && blank($response->json('error'));
        } catch (ConnectionException) {
            return false;
        }
    }

    /** @param array<string, string> $params */
    private function tokenRequest(array $params, bool $refreshing): OAuthTokens
    {
        try {
            $response = $this->client()->timeout(20)->post(self::TOKEN_URL, $params);
        } catch (ConnectionException) {
            throw $this->network();
        }

        if (! $response->successful() || filled($response->json('error'))) {
            throw $this->tokenError($response, $refreshing);
        }

        $access = $response->json('access_token');
        if (! is_string($access) || $access === '') {
            throw new OAuthException('INVALID_TOKEN_RESPONSE', 'Réponse inattendue de X. Réessayez la connexion.');
        }
        $expiresIn = $response->json('expires_in');
        $scope = $response->json('scope');

        return new OAuthTokens(
            accessToken: $access,
            refreshToken: is_string($response->json('refresh_token')) ? $response->json('refresh_token') : null,
            expiresAt: is_numeric($expiresIn) ? CarbonImmutable::now()->addSeconds((int) $expiresIn) : null,
            scopes: is_string($scope) ? array_values(array_filter(preg_split('/\s+/', $scope) ?: [])) : [],
            // X renvoie « bearer » en minuscules : normalisé comme pour les autres réseaux.
            tokenType: 'Bearer',
        );
    }

    /** Requête vers les endpoints OAuth : formulaire, client authentifié en HTTP Basic. */
    private function client(): PendingRequest
    {
        return Http::asForm()->acceptJson()
            ->withBasicAuth((string) config('services.x.client_id'), (string) config('services.x.client_secret'));
    }

    private function tokenError(Response $response, bool $refreshing): OAuthException
    {
        // Seul le code d'erreur est conservé : jamais le corps complet (error_description).
        $error = (string) ($response->json('error') ?: 'http_'.$response->status());

        return match (true) {
            $error === 'invalid_client', $error === 'unauthorized_client' => new OAuthException('OAUTH_CLIENT_INVALID', 'La configuration X du serveur est invalide (client ID ou secret). Contactez l\'administrateur.'),
            // Refresh token déjà utilisé, expiré ou révoqué : X répond invalid_request (ou invalid_grant).
            $refreshing && in_array($error, ['invalid_request', 'invalid_grant'], true) => $this->reconnect('TOKEN_REVOKED'),
            $response->status() === 429 => $this->rateLimited(),
            $response->serverError() => $this->network(),
            default => new OAuthException('OAUTH_FAILED', 'X a refusé l\'autorisation (lien expiré ou adresse de retour différente). Réessayez la connexion.'),
        };
    }

    public function notConfigured(): OAuthException
    {
        return new OAuthException('NOT_CONFIGURED', 'La connexion X n\'est pas configurée sur ce serveur (identifiants X absents).');
    }

    public function reconnect(string $code): OAuthException
    {
        return new OAuthException($code, 'L\'autorisation X a expiré ou a été retirée. Reconnectez le compte puis réessayez.', requiresReconnect: true);
    }

    private function apiAccessDenied(): OAuthException
    {
        return new OAuthException('API_ACCESS_DENIED', 'X refuse l\'accès à son API pour l\'application SoxialConnect (offre ou crédits du compte développeur X). Contactez l\'administrateur.');
    }

    private function rateLimited(): OAuthException
    {
        return new OAuthException('RATE_LIMITED', 'X limite temporairement les requêtes. Réessayez dans quelques minutes.', transient: true);
    }

    private function network(): OAuthException
    {
        return new OAuthException('NETWORK_ERROR', 'X est momentanément injoignable. Réessayez dans un instant.', transient: true);
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw $this->notConfigured();
        }
    }
}
