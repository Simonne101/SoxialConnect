<?php

namespace App\Domain\Social\OAuth;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * LinkedIn OAuth 2.0 « Authorization Code » (3-legged) + identité OpenID Connect (userinfo).
 * Endpoints officiels (learn.microsoft.com/linkedin) :
 * - shared/authentication/authorization-code-flow : /oauth/v2/authorization, /oauth/v2/accessToken ;
 * - consumer/integrations/self-serve/sign-in-with-linkedin-v2 : GET /v2/userinfo.
 *
 * Jeton d'accès valable 60 jours. Pas de refresh token (réservé à certains partenaires) : à
 * expiration, l'utilisateur refait l'autorisation (écran souvent sauté s'il est connecté à LinkedIn).
 * Aucun endpoint de révocation n'est utilisé (non documenté pour ces produits) : la déconnexion
 * efface les tokens côté SoxialConnect. Ne connaît ni la base de données ni le moteur de publication.
 */
final class LinkedInOAuthProvider
{
    public const PROVIDER = 'linkedin';

    /**
     * Scopes des deux produits activés (docs/publishing.md §14) :
     * - openid, profile : identité du membre (sub, nom, photo) ;
     * - email           : adresse e-mail (optionnelle dans la réponse) ;
     * - w_member_social : publier au nom du membre (Share on LinkedIn).
     * Aucune permission d'organisation (pages) : extension future soumise à validation LinkedIn.
     */
    public const SCOPES = ['openid', 'profile', 'email', 'w_member_social'];

    public const PUBLISH_SCOPE = 'w_member_social';

    private const AUTHORIZE_URL = 'https://www.linkedin.com/oauth/v2/authorization';

    private const TOKEN_URL = 'https://www.linkedin.com/oauth/v2/accessToken';

    private const USERINFO_URL = 'https://api.linkedin.com/v2/userinfo';

    public function isConfigured(): bool
    {
        return filled(config('services.linkedin.client_id'))
            && filled(config('services.linkedin.client_secret'))
            && filled(config('services.linkedin.redirect'));
    }

    public function authorizationUrl(string $state): string
    {
        $this->assertConfigured();

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.linkedin.client_id'),
            'redirect_uri' => config('services.linkedin.redirect'),
            'state' => $state,
            'scope' => implode(' ', self::SCOPES),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code): OAuthTokens
    {
        $this->assertConfigured();

        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => config('services.linkedin.client_id'),
                'client_secret' => config('services.linkedin.client_secret'),
                'redirect_uri' => config('services.linkedin.redirect'),
            ]);
        } catch (ConnectionException) {
            throw $this->network();
        }

        if (! $response->successful() || filled($response->json('error'))) {
            throw $this->tokenError($response);
        }

        $access = $response->json('access_token');
        if (! is_string($access) || $access === '') {
            throw new OAuthException('INVALID_TOKEN_RESPONSE', 'Réponse inattendue de LinkedIn. Réessayez la connexion.');
        }
        $expiresIn = $response->json('expires_in');
        $refreshExpiresIn = $response->json('refresh_token_expires_in');
        $scope = $response->json('scope');

        return new OAuthTokens(
            accessToken: $access,
            // Fourni uniquement aux partenaires autorisés (programmatic refresh tokens) : conservé
            // chiffré s'il existe, jamais utilisé tant que ce flux n'est pas implémenté.
            refreshToken: is_string($response->json('refresh_token')) ? $response->json('refresh_token') : null,
            expiresAt: is_numeric($expiresIn) ? CarbonImmutable::now()->addSeconds((int) $expiresIn) : null,
            // Documenté « space-delimited » ; certaines réponses utilisent des virgules : les deux sont acceptés.
            scopes: is_string($scope) ? array_values(array_filter(preg_split('/[\s,]+/', $scope) ?: [])) : [],
            tokenType: 'Bearer',
            refreshExpiresAt: is_numeric($refreshExpiresIn) ? CarbonImmutable::now()->addSeconds((int) $refreshExpiresIn) : null,
        );
    }

    /**
     * Identité du membre (OpenID Connect userinfo). « sub » est l'identifiant du membre, utilisé
     * pour l'auteur des publications (urn:li:person:{sub}).
     *
     * @return array{sub: string, name: string, email: ?string, picture: ?string}
     */
    public function fetchMember(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)->acceptJson()->timeout(20)->get(self::USERINFO_URL);
        } catch (ConnectionException) {
            throw $this->network();
        }

        if ($response->status() === 401) {
            throw $this->reconnect('TOKEN_INVALID');
        }
        if (! $response->successful()) {
            throw new OAuthException('PROFILE_LOOKUP_FAILED', 'Le profil LinkedIn n\'a pas pu être lu. Réessayez.', transient: $response->serverError());
        }

        $sub = $response->json('sub');
        if (! is_string($sub) || $sub === '') {
            throw new OAuthException('INVALID_PROFILE_RESPONSE', 'Réponse inattendue de LinkedIn. Réessayez la connexion.');
        }
        $name = trim((string) ($response->json('name') ?? ''));
        if ($name === '') {
            $name = trim(((string) $response->json('given_name')).' '.((string) $response->json('family_name')));
        }

        return [
            'sub' => $sub,
            'name' => $name !== '' ? $name : 'Profil LinkedIn',
            'email' => is_string($response->json('email')) ? $response->json('email') : null,
            'picture' => is_string($response->json('picture')) ? $response->json('picture') : null,
        ];
    }

    private function tokenError(Response $response): OAuthException
    {
        // Seul le code d'erreur est conservé : jamais le corps complet.
        $error = (string) ($response->json('error') ?: 'http_'.$response->status());

        return match (true) {
            $error === 'invalid_client', $error === 'unauthorized_client' => new OAuthException('OAUTH_CLIENT_INVALID', 'La configuration LinkedIn du serveur est invalide (client ID ou secret). Contactez l\'administrateur.'),
            $error === 'invalid_redirect_uri' => new OAuthException('OAUTH_FAILED', 'LinkedIn a refusé l\'autorisation (lien expiré ou adresse de retour différente). Réessayez la connexion.'),
            $response->serverError() => $this->network(),
            default => new OAuthException('OAUTH_FAILED', 'LinkedIn a refusé l\'autorisation. Réessayez la connexion.'),
        };
    }

    public function notConfigured(): OAuthException
    {
        return new OAuthException('NOT_CONFIGURED', 'La connexion LinkedIn n\'est pas configurée sur ce serveur (identifiants LinkedIn absents).');
    }

    public function reconnect(string $code): OAuthException
    {
        return new OAuthException($code, 'L\'autorisation LinkedIn a expiré ou a été retirée. Reconnectez le compte puis réessayez.', requiresReconnect: true);
    }

    private function network(): OAuthException
    {
        return new OAuthException('NETWORK_ERROR', 'LinkedIn est momentanément injoignable. Réessayez dans un instant.', transient: true);
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw $this->notConfigured();
        }
    }
}
