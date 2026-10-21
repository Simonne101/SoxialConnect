<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tentative de publication (historique). Ni clé d'idempotence, ni identifiant de job, ni extrait
 * de réponse brute : uniquement ce qui aide l'utilisateur à comprendre le résultat. warnings : avertissements
 * d'une tentative réussie (ex. visibilité YouTube différente de la demande), type + message seulement.
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
            'warnings' => $this->warnings(),
        ];
    }

    /** @return list<array{code: string, message: string}> */
    private function warnings(): array
    {
        return collect((array) ($this->response_excerpt['warnings'] ?? []))
            ->filter(fn ($w) => is_array($w) && is_string($w['type'] ?? null) && is_string($w['message'] ?? null))
            ->map(fn (array $w) => ['code' => $w['type'], 'message' => $w['message']])
            ->values()->all();
    }
}
