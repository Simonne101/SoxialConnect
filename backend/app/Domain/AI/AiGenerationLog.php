<?php

namespace App\Domain\AI;

use App\Domain\AI\Enums\AiGenerationStatus;
use App\Domain\AI\Enums\AiTask;
use App\Models\AiGeneration;

/**
 * Historique des appels IA (table ai_generations, ADR-042) : tâche, fournisseur, modèle, statut,
 * tokens, latence et erreur. Jamais de clé ni de réponse brute du fournisseur ; l'entrée et la
 * sortie enregistrées sont des résumés choisis par l'appelant.
 */
final class AiGenerationLog
{
    /** @param array<string, mixed> $input */
    public function start(string $userId, AiTask $task, string $provider, string $model, array $input, ?string $videoProjectId = null, ?string $promptVersion = null): AiGeneration
    {
        $generation = new AiGeneration(['task' => $task, 'input' => $input, 'video_project_id' => $videoProjectId]);
        $generation->user_id = $userId;
        $generation->forceFill([
            'provider' => mb_substr($provider, 0, 32),
            'model' => mb_substr($model, 0, 64),
            'prompt_version' => $promptVersion,
            'status' => AiGenerationStatus::Processing,
        ])->save();

        return $generation;
    }

    /** @param array<string, mixed> $output */
    public function succeed(AiGeneration $generation, array $output, int $latencyMs, ?int $inputTokens = null, ?int $outputTokens = null, ?string $model = null): void
    {
        $generation->forceFill(array_filter([
            'status' => AiGenerationStatus::Succeeded,
            'output' => $output,
            'latency_ms' => $latencyMs,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'model' => $model !== null && $model !== '' ? mb_substr($model, 0, 64) : null,
            'error' => null,
        ], fn ($value) => $value !== null) + ['error' => null])->save();
    }

    public function fail(AiGeneration $generation, string $code, string $message): void
    {
        $generation->forceFill([
            'status' => AiGenerationStatus::Failed,
            'error' => mb_substr($code.' : '.$message, 0, 1000),
        ])->save();
    }
}
