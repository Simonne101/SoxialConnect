<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Social\Enums\WebhookEventStatus;
use App\Domain\Social\Telegram\TelegramUpdateHandler;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramUpdate;
use App\Models\SocialPlatform;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/v1/webhooks/telegram — mises à jour envoyées par Telegram au bot (setWebhook).
 *
 * 1. Authentification : en-tête X-Telegram-Bot-Api-Secret-Token comparé en temps constant au
 *    secret configuré (TELEGRAM_WEBHOOK_SECRET). Sans secret configuré, tout est refusé.
 * 2. Dédoublonnage : update_id unique (webhook_events) ; un renvoi de Telegram est ignoré.
 * 3. Réponse immédiate (200) ; traitement en file (ProcessTelegramUpdate). Seules les données
 *    utiles sont conservées dans le job, rien du contenu des conversations.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        $given = $request->header('X-Telegram-Bot-Api-Secret-Token');
        if ($secret === '' || ! is_string($given) || ! hash_equals($secret, $given)) {
            Log::warning('telegram.webhook_rejected', ['reason' => $secret === '' ? 'not_configured' : 'bad_secret']);

            return response()->json(['message' => 'Action non autorisée.', 'errors' => (object) []], 403);
        }

        $updateId = $request->input('update_id');
        if (! is_int($updateId)) {
            return response()->json(['message' => 'Requête invalide.', 'errors' => (object) []], 400);
        }

        $platform = SocialPlatform::query()->where('key', 'telegram')->firstOrFail();
        $update = TelegramUpdateHandler::normalize($request->all());

        try {
            $event = WebhookEvent::create([
                'social_platform_id' => $platform->getKey(),
                'external_event_id' => (string) $updateId,
                'idempotency_key' => hash('sha256', 'telegram|'.$updateId),
                'event_type' => $update['kind'] ?? 'ignored',
                'signature_valid' => true,
                'payload' => null,
                'received_at' => now(),
                'status' => $update === null ? WebhookEventStatus::Ignored : WebhookEventStatus::Queued,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['ok' => true]); // déjà reçue (renvoi de Telegram)
        }

        if ($update !== null) {
            ProcessTelegramUpdate::dispatch($event->getKey(), $update);
        }

        return response()->json(['ok' => true]);
    }
}
