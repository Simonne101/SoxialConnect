<?php

namespace App\Domain\Social\Telegram;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * Liaison d'une chaîne ou d'un groupe Telegram à un utilisateur SoxialConnect, même principe que
 * OAuthStateStore (docs/security.md §6) :
 *
 * 1. code de liaison : 48 caractères aléatoires, usage unique, valable 10 min, lié à l'utilisateur
 *    authentifié, stocké sous son empreinte SHA-256. Transmis à Telegram par le lien profond
 *    t.me/<bot>?start=<code> (paramètre limité à 64 caractères [A-Za-z0-9_-]) ;
 * 2. session de liaison : une fois le code présenté par /start, l'identifiant Telegram de la
 *    personne qui l'a ouvert est associé à l'utilisateur pendant 10 min. Seuls les choix de
 *    chaîne (chat_shared) envoyés par cette personne, dans ce délai, sont acceptés.
 */
final class TelegramLinkStore
{
    public const TTL_SECONDS = 600;

    public const CODE_LENGTH = 48;

    public function __construct(private readonly Cache $cache) {}

    public function issueCode(User $user): string
    {
        $code = Str::random(self::CODE_LENGTH);
        $this->cache->put($this->codeKey($code), ['user_id' => (string) $user->getKey()], self::TTL_SECONDS);

        return $code;
    }

    /** Consomme le code (usage unique) ; renvoie l'utilisateur lié ou null (inconnu, expiré, déjà utilisé). */
    public function consumeCode(?string $code): ?string
    {
        if (! is_string($code) || strlen($code) !== self::CODE_LENGTH || ! ctype_alnum($code)) {
            return null;
        }
        $payload = $this->cache->pull($this->codeKey($code));

        return is_array($payload) && isset($payload['user_id']) ? (string) $payload['user_id'] : null;
    }

    public function openSession(int $telegramUserId, string $userId): void
    {
        $this->cache->put($this->sessionKey($telegramUserId), ['user_id' => $userId], self::TTL_SECONDS);
    }

    /** Ferme la session (choix de conversation impossible à afficher). */
    public function closeSession(int $telegramUserId): void
    {
        $this->cache->forget($this->sessionKey($telegramUserId));
    }

    /** Utilisateur SoxialConnect associé à cette personne Telegram (session non expirée), sinon null. */
    public function sessionUser(int $telegramUserId): ?string
    {
        $payload = $this->cache->get($this->sessionKey($telegramUserId));

        return is_array($payload) && isset($payload['user_id']) ? (string) $payload['user_id'] : null;
    }

    private function codeKey(string $code): string
    {
        return 'telegram-link-code:'.hash('sha256', $code);
    }

    private function sessionKey(int $telegramUserId): string
    {
        return 'telegram-link-session:'.$telegramUserId;
    }
}
