<?php

namespace App\Domain\Video\Clipper\Transcription;

use App\Domain\AI\AiService;
use App\Domain\AI\Data\AiTranscriptionRequest;
use App\Domain\AI\Data\AiTranscriptionResult;
use App\Domain\AI\Enums\AiCapability;
use App\Domain\AI\Enums\AiTask;
use App\Domain\AI\Exceptions\AiProviderException;
use App\Domain\Video\Rendering\RenderWorkspace;

/** Transcription par le fournisseur IA configuré (ADR-046), via AiService (AI_MODEL_TRANSCRIPTION). */
class AiTranscriber implements Transcriber
{
    public function __construct(private readonly AiService $ai) {}

    public function mode(): string
    {
        return 'ai';
    }

    public function engine(): string
    {
        return $this->ai->providerFor(AiTask::Transcription)->name();
    }

    public function model(): string
    {
        return (string) $this->ai->modelFor(AiTask::Transcription);
    }

    public function audioFormat(): string
    {
        return 'm4a'; // AAC compact : limite de taille du fournisseur
    }

    public function maxDurationMs(): int
    {
        return (int) config('video.clipper.max_source_minutes', 120) * 60_000;
    }

    public function isConfigured(): bool
    {
        return $this->ai->supportsFor(AiTask::Transcription, AiCapability::Transcription);
    }

    /** @throws AiProviderException */
    public function transcribe(string $audioPath, RenderWorkspace $workspace): AiTranscriptionResult
    {
        return $this->ai->transcribe(new AiTranscriptionRequest($this->model(), $audioPath));
    }
}
