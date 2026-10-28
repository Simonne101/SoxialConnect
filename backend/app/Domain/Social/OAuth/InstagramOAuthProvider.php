<?php

namespace App\Domain\Social\OAuth;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * API Instagram avec connexion Instagram (comptes professionnels : Business ou Créateur) —
 * docs/publishing.md §17. Endpoints officiels (developers.facebook.com/docs/instagram-platform) :
 * - instagram-api-with-instagram-login/business-login : www.instagram.com/oauth/authorize,
 *   api.instagram.com/oauth/access_token (jeton courte durée) ;
 * - reference/access_token + refresh_access_token : jeton longue durée (60 jours), renouvelable
 *   tant qu'il est valide et âgé d'au moins 24 h (ig_refresh_token) ;
 * - reference/me : identifiant du compte professionnel (user_id), nom d'utilisateur, type de compte.
 *
 * Jetons transmis dans l'en-tête Authorization, sauf là où la documentation n'admet que l'URL
 * (échange et renouvellement du jeton longue durée) : ces URL ne sont jamais journalisées (seul le
 * code d'erreur l'est). Identifiants PROPRES à l'app Instagram (services.instagram), distincts de
 * l'app Meta (services.facebook). Ne connaît ni la base de données ni le moteur de publication.
 */
final class InstagramOAuthProvider
{
    public const PROVIDER = 'instagram';

    /**
     * Permissions du cas d'usage « Gérer la messagerie et le contenu sur Instagram » :
     * - instagram_business_basic              : profil professionnel (identifiant, nom, photo) ;
     * - instagram_business_content_publish    : publier photos, carrousels, Reels et stories ;
     * - instagram_business_manage_insights    : statistiques du compte et des publications.
     */
    public const SCOPES = ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_insights'];

    public const REQUIRED_SCOPES = ['instagram_business_basic', 'instagram_business_content_publish'];

    /** Types de compte publiables par l'API (un compte personnel doit passer en compte professionnel). */
    public const PROFESSIONAL_TYPES = ['BUSINESS', 'MEDIA_CREATOR'];

    public function isConfigured(): bool
    {
        return filled(config('services.instagram.client_id'))
            && filled(config('services.instagram.client_secret'))
            && filled(config('services.instagram.redirect'));
    }

    public function authorizationUrl(string $state): string
    {
        $this->assertConfigured();

        return 'https://www.instagram.com/oauth/authorize?'.http_build_query([
            'client_id' => config('services.instagram.client_id'),
            'redirect_uri' => config('services.instagram.redirect'),
            'response_type' => 'code',
            'scope' => implode(',', self::SCOPES),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Code → jeton courte durée (1 h) → jeton LONGUE DURÉE (60 jours). Scopes : permissions réellement
     * accordées (renvoyées avec le jeton court).
     */
    public function exchangeCode(string $code): OAuthTokens
    {
        $this->assertConfigured();
        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->post('https://api.instagram.com/oauth/access_token', [
                'client_id' => config('services.instagram.client_id'),
                'client_secret' => config('services.instagram.client_secret'),
                'grant_type' => 'authorization_code',
                'redirect_uri' => config('services.instagram.redirect'),
                // Instagram ajoute parfois « #_ » à la fin du code dans l'URL de retour.
                'code' => preg_replace('/#_$/', '', $code),
            ]);
        } catch (ConnectionException) {
            throw $this->network();
        }
        if (! $response->successful() || filled($response->json('error_type')) || is_array($response->json('error'))) {
            throw $this->tokenError($response);
        }
        // Réponse documentée sous deux formes : { data: [ {...} ] } ou objet direct.
        $row = is_array($response->json('data.0')) ? (array) $response->json('data.0') : (array) $response->json();
        $short = $row['access_token'] ?? null;
        if (! is_string($short) || $short === '') {
            throw new OAuthException('INVALID_TOKEN_RESPONSE', 'Réponse inattendue d\'Instagram. Réessayez la connexion.');
        }
        $permissions = $row['permissions'] ?? [];
        $scopes = is_string($permissions) ? preg_split('/[\s,]+/', $permissions) : (array) $permissions;

        $long = $this->tokenCall('access_token', ['grant_type' => 'ig_exchange_token', 'client_secret' => (string) config('services.instagram.client_secret'), 'access_token' => $short]);

        return new OAuthTokens(
            accessToken: $long['access_token'],
            refreshToken: null,
            expiresAt: $long['expires_at'],
            scopes: array_values(array_filter(array_map('strval', $scopes))),
            tokenType: 'Bearer',
            providerUserId: isset($row['user_id']) ? (string) $row['user_id'] : null,
        );
    }

    /** Nouveau jeton longue durée (60 jours) à partir d'un jeton encore valide et âgé d'au moins 24 h. */
    public function refresh(string $accessToken): OAuthTokens
    {
        $fresh = $this->tokenCall('refresh_access_token', ['grant_type' => 'ig_refresh_token', 'access_token' => $accessToken]);

        return new OAuthTokens(accessToken: $fresh['access_token'], refreshToken: null, expiresAt: $fresh['expires_at'], scopes: []);
    }

    /**
     * Compte professionnel autorisé. user_id : identifiant du compte professionnel Instagram, utilisé
     * pour publier (/{user_id}/media).
     *
     * @return array{user_id: string, username: string, name: string, account_type: string, picture: ?string}
     */
    public function fetchProfile(string $accessToken): array
    {
        $response = $this->get('me', $accessToken, ['fields' => 'user_id,username,name,account_type,profile_picture_url']);
        $userId = $response->json('user_id') ?? $response->json('id');
        if ((! is_string($userId) && ! is_int($userId)) || (string) $userId === '') {
            throw new OAuthException('INVALID_PROFILE_RESPONSE', 'Réponse inattendue d\'Instagram. Réessayez la connexion.');
        }
        $username = trim((string) $response->json('username'));

        return [
            'user_id' => (string) $userId,
            'username' => $username,
            'name' => trim((string) $response->json('name')) ?: ($username !== '' ? '@'.$username : 'Compte Instagram'),
            'account_type' => strtoupper((string) $response->json('account_type')),
            'picture' => is_string($response->json('profile_picture_url')) ? $response->json('profile_picture_url') : null,
        ];
    }

    public function graphUrl(string $path): string
    {
        return 'https://graph.instagram.com/'.$this->version().'/'.ltrim($path, '/');
    }

    public function notConfigured(): OAuthException
    {
        return new OAuthException('NOT_CONFIGURED', 'La connexion Instagram n\'est pas configurée sur ce serveur (identifiants de l\'app Instagram absents).');
    }

    public function reconnect(string $code): OAuthException
    {
        return new OAuthException($code, 'L\'autorisation Instagram a expiré ou a été retirée. Reconnectez le compte puis réessayez.', requiresReconnect: true);
    }

    /**
     * Échange ou renouvellement du jeton longue durée (GET documenté, paramètres dans l'URL).
     *
     * @param  array<string, string>  $query
     * @return array{access_token: string, expires_at: ?CarbonImmutable}
     */
    private function tokenCall(string $path, array $query): array
    {
        try {
            $response = Http::acceptJson()->timeout(20)->get('https://graph.instagram.com/'.$path, $query);
        } catch (ConnectionException) {
            throw $this->network();
        }
        if (! $response->successful() || is_array($response->json('error'))) {
            $code = (int) $response->json('error.code');
            throw match (true) {
                $code === 190 || $response->status() === 401 => $this->reconnect('TOKEN_INVALID'),
                $response->serverError() => $this->network(),
                default => new OAuthException('OAUTH_FAILED', 'Instagram a refusé l\'autorisation. Réessayez la connexion.'),
            };
        }
        $access = $response->json('access_token');
        if (! is_string($access) || $access === '') {
            throw new OAuthException('INVALID_TOKEN_RESPONSE', 'Réponse inattendue d\'Instagram. Réessayez la connexion.');
        }
        $expiresIn = $response->json('expires_in');

        return ['access_token' => $access, 'expires_at' => is_numeric($expiresIn) ? CarbonImmutable::now()->addSeconds((int) $expiresIn) : null];
    }

    /** @param array<string, mixed> $query */
    private function get(string $path, string $token, array $query = []): Response
    {
        try {
            $response = Http::withToken($token)->acceptJson()->timeout(20)->get($this->graphUrl($path), $query);
        } catch (ConnectionException) {
            throw $this->network();
        }
        if ($response->successful() && ! is_array($response->json('error'))) {
            return $response;
        }
        $code = (int) $response->json('error.code');
        throw match (true) {
            $code === 190 || $response->status() === 401 => $this->reconnect('TOKEN_INVALID'),
            in_array($code, [4, 17, 32, 613], true) => new OAuthException('RATE_LIMITED', 'Instagram limite temporairement les requêtes. Réessayez dans quelques minutes.', transient: true),
            $code === 10 || ($code >= 200 && $code < 300) => new OAuthException('PERMISSION_DENIED', 'Instagram refuse l\'accès demandé (permission manquante ou retirée). Reconnectez le compte en acceptant toutes les autorisations.', requiresReconnect: true),
            $response->serverError() => $this->network(),
            default => new OAuthException('GRAPH_ERROR', 'Instagram a refusé la requête. Réessayez la connexion.'),
        };
    }

    private function tokenError(Response $response): OAuthException
    {
        // Seul le type d'erreur est pris en compte : jamais le corps complet.
        $type = (string) ($response->json('error_type') ?? $response->json('error.type') ?? '');
        $message = mb_strtolower((string) ($response->json('error_message') ?? $response->json('error.message') ?? ''));

        return match (true) {
            str_contains($message, 'client secret') || str_contains($message, 'invalid platform app') || str_contains($message, 'client_id') => new OAuthException('OAUTH_CLIENT_INVALID', 'La configuration Instagram du serveur est invalide (ID ou clé secrète de l\'app Instagram). Contactez l\'administrateur.'),
            $response->serverError() => $this->network(),
            $type === 'OAuthException' => new OAuthException('OAUTH_FAILED', 'Instagram a refusé l\'autorisation (lien expiré ou adresse de retour différente). Réessayez la connexion.'),
            default => new OAuthException('OAUTH_FAILED', 'Instagram a refusé l\'autorisation. Réessayez la connexion.'),
        };
    }

    private function network(): OAuthException
    {
        return new OAuthException('NETWORK_ERROR', 'Instagram est momentanément injoignable. Réessayez dans un instant.', transient: true);
    }

    private function version(): string
    {
        $version = (string) config('services.instagram.graph_version', 'v23.0');

        return preg_match('/^v\d+\.\d+$/', $version) ? $version : 'v23.0';
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw $this->notConfigured();
        }
    }
}
