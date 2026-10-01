<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tentative de publication (historique). Ni clé d'idempotence, ni identifiant de job, ni extrait
 * de réponse brute : uniquement ce qui aide l'utilisateur à comprendre le résultat.
 */
class PublicationAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'post_variant_id' => $this->post_variant_id,
            'social_account_id' => $this->social_account_id,
            'attempt_number' => $this->attempt_number,
            'trigger' => $this->trigger->value,
            'status' => $this->status->value,
            'retryable' => $this->retryable,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'external_post_id' => $this->external_post_id,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
