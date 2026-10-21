<?php

namespace App\Domain\Social\OAuth;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * Paramètre `state` OAuth (docs/security.md §6) : 64 caractères aléatoires, usage unique,
 * expiration 10 min, lié à l'utilisateur authentifié et au fournisseur. Stocké côté serveur
 * (cache) sous son empreinte SHA-256, avec le vérificateur PKCE : rien de réutilisable ne
 * transite par le navigateur en dehors du state lui-même.
 */
final class OAuthStateStore
{
    private const TTL_SECONDS = 600;

    public function __construct(private readonly Cache $cache) {}

    /**
     * @param  array<string, string>  $context  données serveur rendues au retour (ex. projet concerné), jamais exposées
     * @return array{state: string, verifier: string}
     */
    public function issue(User $user, string $provider, array $context = []): array
    {
        $state = Str::random(64);
        $verifier = Str::random(96); // PKCE : 43 à 128 caractères [A-Za-z0-9-._~]
        $this->cache->put($this->key($state), [
            'user_id' => $user->getKey(),
            'provider' => $provider,
            'verifier' => $verifier,
            'context' => $context,
        ], self::TTL_SECONDS);

        return ['state' => $state, 'verifier' => $verifier];
    }

    /**
     * Consomme le state (usage unique, même en cas d'échec ultérieur). Renvoie le vérificateur
     * PKCE si le state est connu, non expiré, émis pour ce fournisseur ET pour cet utilisateur.
     */
    public function consume(?string $state, string $provider, User $user): ?string
    {
        return $this->consumeWithContext($state, $provider, $user)['verifier'] ?? null;
    }

    /**
     * Comme consume(), avec le contexte enregistré à l'émission.
     *
     * @return array{verifier: string, context: array<string, string>}|null
     */
    public function consumeWithContext(?string $state, string $provider, User $user): ?array
    {
        if (! is_string($state) || strlen($state) !== 64) {
            return null;
        }
        $payload = $this->cache->pull($this->key($state));
        if (! is_array($payload) || ($payload['provider'] ?? null) !== $provider || ! hash_equals((string) $payload['user_id'], (string) $user->getKey())) {
            return null;
        }

        return ['verifier' => (string) $payload['verifier'], 'context' => (array) ($payload['context'] ?? [])];
    }

    private function key(string $state): string
    {
        return 'oauth-state:'.hash('sha256', $state);
    }
}
