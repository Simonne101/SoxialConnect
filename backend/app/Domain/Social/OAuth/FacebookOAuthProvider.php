<?php

namespace App\Domain\Social\OAuth;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Facebook Login (authorization code) + API Graph pour les Pages (docs/publishing.md §16).
 * Endpoints officiels (developers.facebook.com/docs) :
 * - facebook-login/guides/advanced/manual-flow : /dialog/oauth, /oauth/access_token ;
 * - facebook-login/guides/access-tokens/get-long-lived : échange fb_exchange_token (60 jours) ;
 * - pages-api/overview : GET /me/accounts → jeton de Page de chaque Page administrée.
 *
 * Un jeton de Page obtenu à partir d'un jeton utilisateur LONGUE DURÉE n'expire pas (il cesse de
 * fonctionner si l'utilisateur retire l'accès, change de mot de passe ou perd son rôle sur la Page) :
 * aucun renouvellement, compte « à reconnecter » au premier refus. Chaque appel porte la preuve
 * appsecret_proof (HMAC-SHA256 du jeton par la clé secrète de l'app) ; jetons et clé secrète ne
 * figurent jamais dans une URL. Ne connaît ni la base de données ni le moteur de publication.
 */
final class FacebookOAuthProvider
{
    public const PROVIDER = 'facebook';

    /**
     * Permissions du cas d'usage « Gérer tout sur votre Page » :
     * - pages_show_list       : liste des Pages administrées ;
     * - pages_manage_posts    : publier sur la Page ;
     * - pages_read_engagement : contenu et réactions de la Page (requis avec pages_manage_posts) ;
     * - read_insights         : statistiques de la Page et des publications.
     */
    public const SCOPES = ['pages_show_list', 'pages_manage_posts', 'pages_read_engagement', 'read_insights'];

    /** Sans ces deux permissions, aucune Page ne peut être listée ni alimentée : rien n'est enregistré. */
    public const REQUIRED_SCOPES = ['pages_show_list', 'pages_manage_posts'];

    /** Tâche Facebook qui autorise la publication sur une Page (rôle Admin, Éditeur ou Créateur de contenu). */
    public const PUBLISH_TASK = 'CREATE_CONTENT';

    private const MAX_PAGES = 200;

    public function isConfigured(): bool
    {
        return filled(config('services.facebook.client_id'))
            && filled(config('services.facebook.client_secret'))
            && filled(config('services.facebook.redirect'));
    }

    public function authorizationUrl(string $state): string
    {
        $this->assertConfigured();
        $configId = config('services.facebook.login_config_id');

        return 'https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query(array_filter([
            'client_id' => config('services.facebook.client_id'),
            'redirect_uri' => config('services.facebook.redirect'),
            'state' => $state,
            'response_type' => 'code',
            // Facebook Login for Business : la configuration porte les permissions ; sinon, scopes explicites.
            'config_id' => filled($configId) ? $configId : null,
            'scope' => filled($configId) ? null : implode(',', self::SCOPES),
        ]), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Code → jeton utilisateur de courte durée → jeton utilisateur LONGUE DURÉE (60 jours), indispensable
     * pour obtenir des jetons de Page sans expiration. Scopes : permissions réellement accordées.
     */
    public function exchangeCode(string $code): OAuthTokens
    {
        $this->assertConfigured();
        $short = $this->tokenRequest([
            'client_id' => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'redirect_uri' => config('services.facebook.redirect'),
            'code' => $code,
        ]);
        $long = $this->tokenRequest([
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'fb_exchange_token' => $short['access_token'],
        ]);

        return new OAuthTokens(
            accessToken: $long['access_token'],
            refreshToken: null,
            expiresAt: $long['expires_in'] !== null ? CarbonImmutable::now()->addSeconds($long['expires_in']) : null,
            scopes: $this->grantedScopes($long['access_token']),
            tokenType: 'Bearer',
        );
    }

    /**
     * Identité Facebook de la personne qui autorise (jamais publiée : sert à relier les Pages).
     *
     * @return array{id: string, name: string}
     */
    public function fetchUser(string $userToken): array
    {
        $response = $this->get('/me', $userToken, ['fields' => 'id,name']);
        $id = $response->json('id');
        if (! is_string($id) || $id === '') {
            throw new OAuthException('INVALID_PROFILE_RESPONSE', 'Réponse inattendue de Facebook. Réessayez la connexion.');
        }

        return ['id' => $id, 'name' => trim((string) $response->json('name')) ?: 'Compte Facebook'];
    }

    /**
     * Pages administrées par la personne, avec leur jeton de Page (pages suivantes comprises, bornées).
     *
     * @return list<array{id: string, name: string, access_token: string, tasks: list<string>, category: ?string, picture: ?string}>
     */
    public function fetchPages(string $userToken): array
    {
        $pages = [];
        $query = ['fields' => 'id,name,access_token,tasks,category,picture{url}', 'limit' => 100];
        $path = '/me/accounts';
        do {
            $response = $this->get($path, $userToken, $query);
            foreach ((array) $response->json('data') as $page) {
                if (! is_array($page) || ! is_string($page['id'] ?? null) || ! is_string($page['access_token'] ?? null) || $page['access_token'] === '') {
                    continue;
                }
                $pages[] = [
                    'id' => $page['id'],
                    'name' => trim((string) ($page['name'] ?? '')) ?: 'Page Facebook',
                    'access_token' => $page['access_token'],
                    'tasks' => array_values(array_filter((array) ($page['tasks'] ?? []), 'is_string')),
                    'category' => is_string($page['category'] ?? null) ? $page['category'] : null,
                    'picture' => is_string($page['picture']['data']['url'] ?? null) ? $page['picture']['data']['url'] : null,
                ];
            }
            $after = $response->json('paging.cursors.after');
            $more = is_string($after) && $after !== '' && filled($response->json('paging.next'));
            $query['after'] = $after;
        } while ($more && count($pages) < self::MAX_PAGES);

        return $pages;
    }

    /**
     * Page relue avec son propre jeton (synchronisation) : nom, catégorie, photo.
     *
     * @return array{id: string, name: string, category: ?string, picture: ?string}
     */
    public function fetchPage(string $pageId, string $pageToken): array
    {
        $response = $this->get('/'.rawurlencode($pageId), $pageToken, ['fields' => 'id,name,category,picture{url}']);

        return [
            'id' => (string) $response->json('id'),
            'name' => trim((string) $response->json('name')) ?: 'Page Facebook',
            'category' => is_string($response->json('category')) ? $response->json('category') : null,
            'picture' => is_string($response->json('picture.data.url')) ? $response->json('picture.data.url') : null,
        ];
    }

    /** Preuve exigée par Meta quand l'option « Exiger la clé secrète » est active ; toujours envoyée. */
    public function appSecretProof(string $accessToken): string
    {
        return hash_hmac('sha256', $accessToken, (string) config('services.facebook.client_secret'));
    }

    public function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->version().'/'.ltrim($path, '/');
    }

    public function notConfigured(): OAuthException
    {
        return new OAuthException('NOT_CONFIGURED', 'La connexion Facebook n\'est pas configurée sur ce serveur (identifiants Meta absents).');
    }

    public function reconnect(string $code): OAuthException
    {
        return new OAuthException($code, 'L\'autorisation Facebook a expiré ou a été retirée. Reconnectez la Page puis réessayez.', requiresReconnect: true);
    }

    /** @return list<string> */
    private function grantedScopes(string $userToken): array
    {
        $response = $this->get('/me/permissions', $userToken);
        $granted = [];
        foreach ((array) $response->json('data') as $row) {
            if (is_array($row) && ($row['status'] ?? null) === 'granted' && is_string($row['permission'] ?? null)) {
                $granted[] = $row['permission'];
            }
        }

        return $granted;
    }

    /**
     * @param  array<string, string>  $params
     * @return array{access_token: string, expires_in: ?int}
     */
    private function tokenRequest(array $params): array
    {
        try {
            // Formulaire POST : la clé secrète et le code ne figurent jamais dans une URL (journaux).
            $response = Http::asForm()->acceptJson()->timeout(20)->post($this->graphUrl('/oauth/access_token'), $params);
        } catch (ConnectionException) {
            throw $this->network();
        }
        if (! $response->successful() || is_array($response->json('error'))) {
            throw $this->tokenError($response);
        }
        $access = $response->json('access_token');
        if (! is_string($access) || $access === '') {
            throw new OAuthException('INVALID_TOKEN_RESPONSE', 'Réponse inattendue de Facebook. Réessayez la connexion.');
        }
        $expiresIn = $response->json('expires_in');

        return ['access_token' => $access, 'expires_in' => is_numeric($expiresIn) ? (int) $expiresIn : null];
    }

    /** @param array<string, mixed> $query */
    private function get(string $path, string $token, array $query = []): Response
    {
        try {
            // Jeton dans l'en-tête Authorization (jamais dans l'URL) ; la preuve HMAC n'est pas secrète.
            $response = Http::withToken($token)->acceptJson()->timeout(20)->get($this->graphUrl($path), $query + [
                'appsecret_proof' => $this->appSecretProof($token),
            ]);
        } catch (ConnectionException) {
            throw $this->network();
        }
        if ($response->successful() && ! is_array($response->json('error'))) {
            return $response;
        }

        $code = (int) $response->json('error.code');
        throw match (true) {
            $code === 190 || $response->status() === 401 => $this->reconnect('TOKEN_INVALID'),
            in_array($code, [4, 17, 32, 613], true) => new OAuthException('RATE_LIMITED', 'Facebook limite temporairement les requêtes. Réessayez dans quelques minutes.', transient: true),
            $code === 10 || ($code >= 200 && $code < 300) => new OAuthException('PERMISSION_DENIED', 'Facebook refuse l\'accès demandé (permission manquante ou retirée). Reconnectez la Page en acceptant toutes les autorisations.', requiresReconnect: true),
            $response->serverError() => $this->network(),
            default => new OAuthException('GRAPH_ERROR', 'Facebook a refusé la requête. Réessayez la connexion.'),
        };
    }

    private function tokenError(Response $response): OAuthException
    {
        // Seuls le code et le sous-code d'erreur sont pris en compte : jamais le corps complet.
        $code = (int) $response->json('error.code');

        return match (true) {
            // 101 : ID d'app inconnu ; 1 + « client secret » : clé secrète refusée.
            $code === 101 || ($code === 1 && str_contains(mb_strtolower((string) $response->json('error.message')), 'client')) => new OAuthException('OAUTH_CLIENT_INVALID', 'La configuration Facebook du serveur est invalide (ID ou clé secrète de l\'app Meta). Contactez l\'administrateur.'),
            $code === 100 || $code === 191 => new OAuthException('OAUTH_FAILED', 'Facebook a refusé l\'autorisation (lien expiré ou adresse de retour différente). Réessayez la connexion.'),
            $response->serverError() => $this->network(),
            default => new OAuthException('OAUTH_FAILED', 'Facebook a refusé l\'autorisation. Réessayez la connexion.'),
        };
    }

    private function network(): OAuthException
    {
        return new OAuthException('NETWORK_ERROR', 'Facebook est momentanément injoignable. Réessayez dans un instant.', transient: true);
    }

    private function version(): string
    {
        $version = (string) config('services.facebook.graph_version', 'v23.0');

        return preg_match('/^v\d+\.\d+$/', $version) ? $version : 'v23.0';
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw $this->notConfigured();
        }
    }
}
