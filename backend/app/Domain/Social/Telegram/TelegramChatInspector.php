<?php

namespace App\Domain\Social\Telegram;

/**
 * Vérifie une destination Telegram directement auprès de la Bot API (jamais sur la seule foi des
 * données reçues) : type de conversation, titre, nom public, droits RÉELS du bot et, lors d'une
 * liaison, statut d'administrateur de la personne qui la demande.
 */
final class TelegramChatInspector
{
    public const LINKABLE_TYPES = ['channel', 'group', 'supergroup'];

    public function __construct(private readonly TelegramBotClient $client) {}

    /**
     * @param  int|null  $requestedBy  identifiant Telegram de la personne qui relie la conversation :
     *                                 elle doit en être propriétaire ou administratrice.
     * @return array{chat_id: string, type: string, title: string, username: ?string}
     *
     * @throws TelegramLinkException refus (droits, type, accès) — message pour l'utilisateur
     * @throws TelegramApiException erreur passagère de Telegram
     */
    public function inspect(int|string $chatId, ?int $requestedBy = null): array
    {
        try {
            $chat = $this->client->call('getChat', ['chat_id' => $chatId]);
        } catch (TelegramApiException $e) {
            throw $e->transient ? $e : new TelegramLinkException('Le bot n\'a pas accès à cette conversation. Ajoutez-le comme administrateur, puis réessayez.');
        }
        if (! is_array($chat) || ! isset($chat['id'], $chat['type'])) {
            throw TelegramApiException::invalidResponse();
        }
        $type = (string) $chat['type'];
        if (! in_array($type, self::LINKABLE_TYPES, true)) {
            throw new TelegramLinkException('Seules les chaînes et les groupes Telegram peuvent être reliés.');
        }
        $title = trim((string) ($chat['title'] ?? '')) ?: 'Conversation Telegram';

        $this->assertBotCanPost($chat['id'], $type, $title);
        if ($requestedBy !== null) {
            $this->assertIsAdmin($chat['id'], $requestedBy, $title);
        }

        return [
            'chat_id' => (string) $chat['id'],
            'type' => $type,
            'title' => mb_substr($title, 0, 255),
            'username' => filled($chat['username'] ?? null) ? '@'.$chat['username'] : null,
        ];
    }

    /** Raison du refus si le bot ne peut pas publier (null = il peut publier). */
    public function botPostingProblem(array $member, string $type): ?string
    {
        $status = (string) ($member['status'] ?? '');

        if ($type === 'channel') {
            return $status === 'administrator' && ($member['can_post_messages'] ?? false) === true
                ? null
                : 'Le bot doit être administrateur de la chaîne avec le droit « Publier des messages ».';
        }

        return match (true) {
            in_array($status, ['administrator', 'member'], true) => null,
            $status === 'restricted' && ($member['is_member'] ?? false) === true && ($member['can_send_messages'] ?? false) === true => null,
            default => 'Le bot doit être membre du groupe et autorisé à y envoyer des messages.',
        };
    }

    private function assertBotCanPost(int|string $chatId, string $type, string $title): void
    {
        $member = $this->member($chatId, $this->client->me()['id']);
        $problem = $this->botPostingProblem($member ?? [], $type);
        if ($problem !== null) {
            throw new TelegramLinkException("« {$title} » : {$problem}");
        }
    }

    private function assertIsAdmin(int|string $chatId, int $userId, string $title): void
    {
        $member = $this->member($chatId, $userId);
        if (! in_array($member['status'] ?? null, ['creator', 'administrator'], true)) {
            throw new TelegramLinkException("Vous devez être propriétaire ou administrateur de « {$title} » pour la relier à SoxialConnect.");
        }
    }

    /** @return array<string, mixed>|null */
    private function member(int|string $chatId, int $userId): ?array
    {
        try {
            $member = $this->client->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
        } catch (TelegramApiException $e) {
            if ($e->transient) {
                throw $e;
            }

            return null;
        }

        return is_array($member) ? $member : null;
    }
}
