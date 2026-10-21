<?php

namespace App\Console\Commands;

use App\Domain\Social\Telegram\TelegramApiException;
use App\Domain\Social\Telegram\TelegramBotClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Webhook du bot Telegram partagé (docs/publishing.md §13) :
 * - --info (défaut) : bot (getMe) et état du webhook (getWebhookInfo) ;
 * - --set : enregistre TELEGRAM_WEBHOOK_URL avec le secret TELEGRAM_WEBHOOK_SECRET ;
 * - --delete : supprime le webhook (le bot ne reçoit plus rien).
 * N'affiche jamais le token ni le secret.
 */
#[Signature('telegram:webhook {--set : Enregistrer le webhook} {--delete : Supprimer le webhook} {--drop-pending : Ignorer les mises à jour en attente (avec --set ou --delete)}')]
#[Description('Affiche, enregistre ou supprime le webhook du bot Telegram')]
class TelegramWebhook extends Command
{
    public const ALLOWED_UPDATES = ['message', 'my_chat_member'];

    public function handle(TelegramBotClient $telegram): int
    {
        if (! $telegram->isConfigured()) {
            $this->components->error('TELEGRAM_BOT_TOKEN est absent : aucun bot configuré.');

            return self::FAILURE;
        }

        try {
            $me = $telegram->me();
            $this->components->twoColumnDetail('Bot', '@'.$me['username'].' (id '.$me['id'].')');
            $expected = $telegram->botUsername();
            if ($expected !== '' && strcasecmp($expected, $me['username']) !== 0) {
                $this->components->warn("TELEGRAM_BOT_USERNAME ({$expected}) ne correspond pas au bot du token (@{$me['username']}).");
            }

            if ($this->option('set')) {
                return $this->set($telegram);
            }
            if ($this->option('delete')) {
                $telegram->call('deleteWebhook', ['drop_pending_updates' => (bool) $this->option('drop-pending')]);
                $this->components->info('Webhook supprimé.');

                return self::SUCCESS;
            }

            return $this->showInfo($telegram);
        } catch (TelegramApiException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function set(TelegramBotClient $telegram): int
    {
        $url = (string) config('services.telegram.webhook_url');
        $secret = (string) config('services.telegram.webhook_secret');
        if (! str_starts_with($url, 'https://')) {
            $this->components->error('TELEGRAM_WEBHOOK_URL doit être une URL HTTPS publique (ex. https://<domaine-du-tunnel>/api/v1/webhooks/telegram).');

            return self::FAILURE;
        }
        if (! preg_match('/^[A-Za-z0-9_-]{16,256}$/', $secret)) {
            $this->components->error('TELEGRAM_WEBHOOK_SECRET doit contenir 16 à 256 caractères A-Z, a-z, 0-9, _ ou -.');

            return self::FAILURE;
        }

        $telegram->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => self::ALLOWED_UPDATES,
            'drop_pending_updates' => (bool) $this->option('drop-pending'),
        ]);
        $this->components->info('Webhook enregistré : '.$url);

        return $this->showInfo($telegram);
    }

    private function showInfo(TelegramBotClient $telegram): int
    {
        $info = (array) $telegram->call('getWebhookInfo');
        $this->components->twoColumnDetail('URL du webhook', (string) (($info['url'] ?? '') ?: '(aucune)'));
        $this->components->twoColumnDetail('Mises à jour en attente', (string) ($info['pending_update_count'] ?? 0));
        $this->components->twoColumnDetail('Mises à jour reçues', implode(', ', (array) ($info['allowed_updates'] ?? [])) ?: '(par défaut)');
        if (isset($info['last_error_date'])) {
            $this->components->twoColumnDetail('Dernière erreur', date('Y-m-d H:i:s', (int) $info['last_error_date']).' — '.($info['last_error_message'] ?? ''));
        }

        return self::SUCCESS;
    }
}
