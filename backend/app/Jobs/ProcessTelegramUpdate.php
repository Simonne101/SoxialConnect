<?php

namespace App\Jobs;

use App\Domain\Social\Enums\WebhookEventStatus;
use App\Domain\Social\Telegram\TelegramUpdateHandler;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Traite une mise à jour Telegram déjà authentifiée et dédoublonnée par le webhook. Le webhook
 * répond immédiatement à Telegram ; les appels à la Bot API (vérifications, réponses) se font ici.
 * Les données reçues sont déjà réduites au strict nécessaire (TelegramUpdateHandler::normalize).
 */
class ProcessTelegramUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [5, 30];

    /** @param array<string, mixed> $update */
    public function __construct(public string $webhookEventId, public array $update) {}

    public function handle(TelegramUpdateHandler $handler): void
    {
        WebhookEvent::query()->whereKey($this->webhookEventId)->increment('attempts', 1, ['status' => WebhookEventStatus::Processing->value]);

        $handler->handle($this->update);

        WebhookEvent::query()->whereKey($this->webhookEventId)->update([
            'status' => WebhookEventStatus::Processed->value,
            'processed_at' => now(),
            'error' => null,
        ]);
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('telegram.update_failed', ['webhook_event_id' => $this->webhookEventId, 'exception' => $e ? $e::class : null]);
        WebhookEvent::query()->whereKey($this->webhookEventId)->update([
            'status' => WebhookEventStatus::Failed->value,
            'error' => $e ? mb_substr($e->getMessage(), 0, 500) : null,
        ]);
    }
}
