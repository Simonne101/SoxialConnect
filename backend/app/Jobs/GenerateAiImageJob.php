<?php

namespace App\Jobs;

use App\Domain\AI\AiGenerationLog;
use App\Domain\AI\Enums\AiGenerationStatus;
use App\Domain\AI\Images\AiImageService;
use App\Models\AiGeneration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Génération d'une image par IA (ADR-046) sur la file « ai » (connexion par défaut). Délai du
 * fournisseur (config ai.images.timeout, 180 s) + import < timeout du job < retry_after de « redis ».
 */
class GenerateAiImageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $generationId)
    {
        $connection = config('ai.queue.connection');
        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }
        $this->onQueue(config('ai.queue.name', 'ai'));
    }

    public function handle(AiImageService $images): void
    {
        $images->run($this->generationId);
    }

    public function failed(?Throwable $exception): void
    {
        $generation = AiGeneration::query()->find($this->generationId);
        if ($generation !== null && in_array($generation->status, [AiGenerationStatus::Pending, AiGenerationStatus::Processing], true)) {
            app(AiGenerationLog::class)->fail($generation, 'IMAGE_ABORTED', 'La génération de l\'image a été interrompue.');
        }
    }
}
