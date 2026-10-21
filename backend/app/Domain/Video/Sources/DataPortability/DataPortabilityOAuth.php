<?php

namespace App\Domain\Video\Sources\DataPortability;

use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\OAuth\OAuthTokens;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Consentement Google OAuth 2.0 (code + PKCE S256) pour la Data Portability API (ADR-054). Scopes
 * dataportability.<groupe> uniquement, jamais mélangés à d'autres scopes ni à include_granted_scopes
 * (recommandation Google). access_type=offline : jeton de rafraîchissement si l'utilisateur choisit
 * un accès de 30 ou 180 jours ; accès ponctuel sinon. Endpoints officiels Google, aucun SDK.
 */
class DataPortabilityOAuth
{
    public const PROVIDER = 'data_portability';

    public const SCOPE_PREFIX = 'https://www.googleapis.com/auth/dataportability.';

    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public function isConfigured(): bool
    {
        $config = (array) config('video.sources.data_portability');

        return ($config['enabled'] ?? false) === true && filled($config['client_id'] ?? null)
            && filled($config['client_secret'] ?? null) && filled($config['redirect'] ?? null) && $this->resources() !== [];
    }

    /** @return list<string> groupes de ressources demandés (ex. youtube.public_videos) */
    public function resources(): array
    {
        return array_values(array_filter((array) config('video.sources.data_portability.resources', []), fn ($r) => is_string($r) && preg_match('/^youtube\.[a-z_]+$/', $r)));
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return array_map(fn (string $resource) => self::SCOPE_PREFIX.$resource, $this->resources());
    }

    /**
     * Groupes de ressources réellement accordés (consentement partiel possible sur l'écran Google).
     *
     * @param  list<string>  $grantedScopes
     * @return list<string>
     */
    public function grantedResources(array $grantedScopes): array
    {
        return array_values(array_filter($this->resources(), fn (string $resource) => in_array(self::SCOPE_PREFIX.$resource, $grantedScopes, true)));
    }

    public function authorizationUrl(string $state, string $verifier): string
    {
        $this->assertConfigured();

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('video.sources.data_portability.client_id'),
            'redirect_uri' => config('video.sources.data_portability.redirect'),
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'access_type' => 'offline',
            'prompt' => 'consent select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @throws OAuthException */
    public function exchangeCode(string $code, string $verifier): OAuthTokens
    {
        $this->assertConfigured();

        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => config('video.sources.data_portability.redirect'),
        ]);
    }

    /** @throws OAuthException */
    public function refresh(string $refreshToken): OAuthTokens
    {
        $this->assertConfigured();

        return $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /** Révocation côté Google (au mieux). */
    public function revoke(string $token): bool
    {
        try {
            return Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token])->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * @param  array<string, string>  $params
     *
     * @throws OAuthException
     */
    private function tokenRequest(array $params): OAuthTokens
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->post(self::TOKEN_URL, $params + [
                'client_id' => config('video.sources.data_portability.client_id'),
                'client_secret' => config('video.sources.data_portability.client_secret'),
            ]);
        } catch (ConnectionException) {
            throw OAuthException::network();
        }
        if (! $response->successful()) {
            throw $this->tokenError($response);
        }
        $access = $response->json('access_token');
        if (! is_string($access) || $access === '') {
            throw new OAuthException('INVALID_TOKEN_RESPONSE', 'Réponse inattendue de Google. Réessayez l\'autorisation.');
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
        // Seul le code d'erreur OAuth est conservé (jamais le corps de la réponse).
        $error = (string) ($response->json('error') ?? 'http_'.$response->status());

        return match (true) {
            $error === 'invalid_grant' => OAuthException::reconnectRequired('TOKEN_REVOKED'),
            $error === 'invalid_client', $error === 'unauthorized_client' => new OAuthException('OAUTH_CLIENT_INVALID', 'La configuration OAuth Google est invalide (identifiant ou secret client).'),
            $response->serverError() => OAuthException::network(),
            default => new OAuthException('OAUTH_FAILED', 'Google a refusé l\'autorisation d\'export. Réessayez.'),
        };
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new OAuthException('NOT_CONFIGURED', 'L\'export officiel Google n\'est pas configuré sur ce serveur.');
        }
    }
}
