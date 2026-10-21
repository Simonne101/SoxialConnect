<?php

namespace App\Domain\Social\Telegram;

use App\Domain\Social\Actions\ConnectTelegramChat;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Traitement des mises à jour reçues par le webhook Telegram (docs/publishing.md §13).
 *
 * Liaison d'une chaîne ou d'un groupe (mécanisme officiel, sans OAuth) :
 * 1. SoxialConnect affiche le lien t.me/<bot>?start=<code> (code à usage unique, 10 min) ;
 * 2. « /start <code> » en conversation privée → session de liaison ouverte pour cette personne,
 *    le bot répond avec deux boutons « choisir une chaîne / un groupe » (KeyboardButtonRequestChat)
 *    qui exigent qu'elle en soit administratrice et y ajoutent le bot avec les droits nécessaires ;
 * 3. message « chat_shared » → vérification auprès de Telegram (droits du bot, statut
 *    d'administrateur) → ConnectTelegramChat.
 *
 * Suivi : « my_chat_member » (bot retiré, rétrogradé ou rétabli) met à jour le statut des liaisons ;
 * la migration d'un groupe en supergroupe met à jour son identifiant.
 * Aucun contenu de conversation n'est conservé.
 */
final class TelegramUpdateHandler
{
    public const REQUEST_CHANNEL = 1;

    public const REQUEST_GROUP = 2;

    public function __construct(
        private readonly TelegramBotClient $client,
        private readonly TelegramLinkStore $links,
        private readonly TelegramChatInspector $inspector,
        private readonly ConnectTelegramChat $connect,
    ) {}

    /**
     * Extrait d'une mise à jour brute les seules données utiles (null = mise à jour ignorée).
     * Le texte des messages n'est jamais conservé, sauf l'argument de « /start ».
     *
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>|null
     */
    public static function normalize(array $update): ?array
    {
        $message = $update['message'] ?? null;
        if (is_array($message)) {
            $chat = (array) ($message['chat'] ?? []);
            $fromId = (int) ($message['from']['id'] ?? 0);
            $private = ($chat['type'] ?? null) === 'private';

            if ($private && $fromId > 0 && isset($message['chat_shared']['chat_id'])) {
                return [
                    'kind' => 'chat_shared',
                    'from_id' => $fromId,
                    'private_chat_id' => (int) $chat['id'],
                    'request_id' => (int) ($message['chat_shared']['request_id'] ?? 0),
                    'shared_chat_id' => (int) $message['chat_shared']['chat_id'],
                ];
            }
            $text = is_string($message['text'] ?? null) ? trim($message['text']) : '';
            if ($private && $fromId > 0 && preg_match('/^\/start(?:@\w+)?(?:\s+(\S+))?$/', $text, $m)) {
                return [
                    'kind' => 'start',
                    'from_id' => $fromId,
                    'private_chat_id' => (int) $chat['id'],
                    'payload' => $m[1] ?? null,
                ];
            }
            if (isset($message['migrate_to_chat_id'], $chat['id'])) {
                return ['kind' => 'migrate', 'from_chat_id' => (int) $chat['id'], 'to_chat_id' => (int) $message['migrate_to_chat_id']];
            }

            return null;
        }

        $member = $update['my_chat_member'] ?? null;
        if (is_array($member) && isset($member['chat']['id'], $member['new_chat_member']['status'])) {
            $new = (array) $member['new_chat_member'];

            return [
                'kind' => 'bot_status',
                'chat_id' => (int) $member['chat']['id'],
                'chat_type' => (string) ($member['chat']['type'] ?? ''),
                'member' => array_intersect_key($new, array_flip(['status', 'can_post_messages', 'is_member', 'can_send_messages'])),
            ];
        }

        return null;
    }

    /** @param array<string, mixed> $update mise à jour normalisée (normalize) */
    public function handle(array $update): void
    {
        match ($update['kind'] ?? null) {
            'start' => $this->onStart($update),
            'chat_shared' => $this->onChatShared($update),
            'bot_status' => $this->onBotStatus($update),
            'migrate' => $this->onMigrate($update),
            default => null,
        };
    }

    /** @param array<string, mixed> $u */
    private function onStart(array $u): void
    {
        if ($u['payload'] === null) {
            $this->reply($u['private_chat_id'], 'Bonjour ! Ce bot publie pour SoxialConnect. Pour relier une chaîne ou un groupe, ouvrez SoxialConnect › Réseaux sociaux › Telegram › Connecter.');

            return;
        }

        // Code consommé (usage unique) ; nouvel essai du même message : session déjà ouverte.
        $userId = $this->links->consumeCode($u['payload']) ?? $this->links->sessionUser($u['from_id']);
        if ($userId === null || ! User::query()->whereKey($userId)->exists()) {
            $this->reply($u['private_chat_id'], 'Ce lien de connexion a expiré ou a déjà été utilisé. Relancez « Connecter Telegram » depuis SoxialConnect.', ['remove_keyboard' => true]);

            return;
        }

        // Session ouverte AVANT l'envoi : un échec passager relance le job, qui la retrouvera (le code
        // est déjà consommé). Refus définitif de Telegram : session refermée, message de secours.
        $this->links->openSession($u['from_id'], $userId);
        $sent = $this->reply(
            $u['private_chat_id'],
            "Choisissez la chaîne ou le groupe à relier à SoxialConnect.\n\nTelegram vous proposera d'y ajouter ce bot comme administrateur : pour une chaîne, il doit pouvoir publier des messages. Vous devez vous-même en être administrateur.\n\nCe choix est valable 10 minutes.",
            [
                'keyboard' => [
                    [['text' => 'Choisir une chaîne', 'request_chat' => $this->chatRequest(self::REQUEST_CHANNEL, channel: true)]],
                    [['text' => 'Choisir un groupe', 'request_chat' => $this->chatRequest(self::REQUEST_GROUP, channel: false)]],
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ],
            operation: 'start.chat_picker',
        );
        if (! $sent) {
            // Code à usage unique déjà consommé : une nouvelle connexion depuis SoxialConnect est nécessaire.
            $this->links->closeSession($u['from_id']);
            $this->reply(
                $u['private_chat_id'],
                'Une erreur empêche d\'afficher le choix de la chaîne ou du groupe. Relancez « Connecter Telegram » depuis SoxialConnect. Si le problème persiste, contactez le support.',
                ['remove_keyboard' => true],
                operation: 'start.chat_picker_fallback',
            );
        }
    }

    /** @param array<string, mixed> $u */
    private function onChatShared(array $u): void
    {
        $userId = $this->links->sessionUser($u['from_id']);
        $user = $userId ? User::query()->find($userId) : null;
        if ($user === null) {
            $this->reply($u['private_chat_id'], 'Cette demande a expiré. Relancez « Connecter Telegram » depuis SoxialConnect.', ['remove_keyboard' => true]);

            return;
        }

        try {
            $chat = $this->inspector->inspect($u['shared_chat_id'], $u['from_id']);
        } catch (TelegramLinkException $e) {
            $this->reply($u['private_chat_id'], $e->getMessage().' Corrigez puis choisissez à nouveau la conversation.');

            return;
        }

        $this->connect->handle($user, $chat);
        $kind = $chat['type'] === 'channel' ? 'La chaîne' : 'Le groupe';
        $this->reply($u['private_chat_id'], "{$kind} « {$chat['title']} » est relié(e) à SoxialConnect. Vous pouvez revenir sur SoxialConnect pour publier.", ['remove_keyboard' => true]);
    }

    /** Bot retiré, rétrogradé ou rétabli : statut des liaisons existantes (tous utilisateurs). @param array<string, mixed> $u */
    private function onBotStatus(array $u): void
    {
        $problem = $this->inspector->botPostingProblem($u['member'], $u['chat_type']);
        $accounts = SocialAccount::query()
            ->whereHas('platform', fn ($q) => $q->where('key', ConnectTelegramChat::PROVIDER))
            ->where('external_account_id', (string) $u['chat_id'])
            ->whereIn('status', [SocialAccountStatus::Connected->value, SocialAccountStatus::Error->value])
            ->get();

        foreach ($accounts as $account) {
            if ($problem !== null && $account->status === SocialAccountStatus::Connected) {
                $account->forceFill([
                    'status' => SocialAccountStatus::Error,
                    'status_reason' => $problem.' Rétablissez ce droit dans Telegram, puis reconnectez la destination.',
                    'last_error_at' => now(),
                ])->save();
            } elseif ($problem === null && $account->status === SocialAccountStatus::Error) {
                $account->forceFill(['status' => SocialAccountStatus::Connected, 'status_reason' => null])->save();
            }
        }
    }

    /** Groupe devenu supergroupe : nouvel identifiant de conversation. @param array<string, mixed> $u */
    private function onMigrate(array $u): void
    {
        SocialAccount::withTrashed()
            ->whereHas('platform', fn ($q) => $q->where('key', ConnectTelegramChat::PROVIDER))
            ->where('external_account_id', (string) $u['from_chat_id'])
            ->get()
            ->each(function (SocialAccount $account) use ($u) {
                $taken = SocialAccount::withTrashed()
                    ->where('user_id', $account->user_id)
                    ->where('social_platform_id', $account->social_platform_id)
                    ->where('external_account_id', (string) $u['to_chat_id'])
                    ->exists();
                if (! $taken) {
                    $account->forceFill([
                        'external_account_id' => (string) $u['to_chat_id'],
                        'metadata' => array_merge($account->metadata ?? [], ['chat_type' => 'supergroup']),
                    ])->save();
                }
            });
    }

    /**
     * Bouton officiel de choix d'une conversation (KeyboardButtonRequestChat). La personne doit en
     * être administratrice ; le bot y est ajouté avec les droits demandés.
     *
     * Droits (ChatAdministratorRights, https://core.telegram.org/bots/api#chatadministratorrights) :
     * - tous les champs non « Optional » sont fournis explicitement, dont can_send_welcome_messages ;
     * - Telegram refuse des droits entièrement à false (« ADMIN_RIGHTS_EMPTY ») : can_manage_chat,
     *   le droit d'administrateur minimal (« implied by any other administrator privilege »), est
     *   demandé pour la chaîne comme pour le groupe ;
     * - chaîne : can_post_messages en plus (publier) ; groupe : rien d'autre (un administrateur
     *   peut écrire, y compris en mode lent). Aucun droit de modération, d'invitation ou de gestion.
     *
     * @return array<string, mixed>
     */
    private function chatRequest(int $requestId, bool $channel): array
    {
        $rights = [
            'is_anonymous' => false, 'can_manage_chat' => true, 'can_delete_messages' => false,
            'can_manage_video_chats' => false, 'can_restrict_members' => false, 'can_promote_members' => false,
            'can_change_info' => false, 'can_invite_users' => false, 'can_post_stories' => false,
            'can_edit_stories' => false, 'can_delete_stories' => false, 'can_send_welcome_messages' => false,
        ];
        if ($channel) {
            $rights['can_post_messages'] = true;
        }

        return [
            'request_id' => $requestId,
            'chat_is_channel' => $channel,
            'user_administrator_rights' => $rights,
            'bot_administrator_rights' => $rights,
        ];
    }

    /**
     * Réponse en conversation privée. Renvoie false si Telegram l'a refusée définitivement (motif
     * journalisé) ; échec passager : exception, nouvel essai du job.
     */
    private function reply(int $chatId, string $text, ?array $markup = null, string $operation = 'reply'): bool
    {
        try {
            $this->client->call('sendMessage', array_filter([
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => $markup,
            ], fn ($v) => $v !== null));

            return true;
        } catch (TelegramApiException $e) {
            if ($e->transient && ! $e->maybeSent) {
                throw $e;
            }
            // Motif renvoyé par Telegram (ex. « Bad Request: ADMIN_RIGHTS_EMPTY »), sans identifiant
            // de conversation, sans token ni secret, sans code de liaison.
            Log::warning('telegram.reply_failed', [
                'operation' => $operation,
                'http_status' => $e->httpStatus,
                'error_code' => $e->errorCode,
                'description' => $this->safe($e->description),
            ]);

            return false;
        }
    }

    /** Retire d'un texte destiné aux journaux tout secret ou code de liaison éventuel. */
    private function safe(string $text): string
    {
        foreach (['services.telegram.bot_token', 'services.telegram.webhook_secret'] as $key) {
            $secret = (string) config($key);
            if ($secret !== '') {
                $text = str_replace($secret, '[MASQUÉ]', $text);
            }
        }

        return (string) preg_replace('/[A-Za-z0-9]{'.TelegramLinkStore::CODE_LENGTH.',}/', '[MASQUÉ]', $text);
    }
}
