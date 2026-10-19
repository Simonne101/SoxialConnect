<?php

namespace App\Domain\Video\Clipper\Analysis;

use App\Domain\AI\AiGenerationLog;
use App\Domain\AI\AiService;
use App\Domain\AI\Enums\AiCapability;
use App\Domain\AI\Enums\AiTask;
use App\Domain\AI\Exceptions\AiProviderException;
use App\Domain\Video\Clipper\TranscriptAnalyzer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Analyse par IA (ADR-046) : TranscriptAnalyzer (modèle de texte, JSON revalidé), appel tracé dans
 * ai_generations. Disponible seulement si le fournisseur et le modèle de la tâche sont configurés.
 */
class AiClipAnalyzer implements ClipAnalyzer
{
    public function __construct(
        private readonly AiService $ai,
        private readonly TranscriptAnalyzer $analyzer,
        private readonly AiGenerationLog $log,
    ) {}

    public function mode(): string
    {
        return 'ai';
    }

    public function requiresTranscript(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return $this->ai->supportsFor(AiTask::VideoAnalysis, AiCapability::Text);
    }

    /** Le fournisseur de l'analyse sait regarder une vidéo YouTube publique par son URL (ADR-048). */
    public function supportsUrl(): bool
    {
        return $this->ai->supportsFor(AiTask::VideoAnalysis, AiCapability::VideoUrl, $this->ai->urlProviderNameFor(AiTask::VideoAnalysis));
    }

    /** Erreurs passagères du modèle principal pour lesquelles le modèle de secours (s'il est réglé) est essayé. */
    private const FALLBACK_ON = ['QUOTA_EXCEEDED', 'PROVIDER_UNAVAILABLE', 'TIMEOUT', 'RATE_LIMITED', 'NETWORK_ERROR'];

    /** Dernière analyse par lien réalisée avec le modèle de secours : [modèle, code de l'échec du principal]. */
    private ?array $fallbackUsed = null;

    /**
     * Passages proposés depuis l'URL de la vidéo, sans fichier source (ADR-048), tracé dans ai_generations.
     * Modèle principal indisponible (quota, surcharge, délai) et modèle de secours réglé
     * (ai.tasks.video_analysis.url_fallback_model) : UNE nouvelle tentative avec ce modèle, tracée comme un
     * appel distinct et signalée (fallbackUsed()) — jamais présentée comme une analyse du modèle principal.
     *
     * @return list<array{start_ms: int, end_ms: int, title: string, topic: string, reason: string, score: int}>
     *
     * @throws AiProviderException
     */
    public function analyzeUrl(string $canonicalUrl, string $title, string $projectUserId, string $projectId): array
    {
        $this->fallbackUsed = null;
        $provider = (string) $this->ai->urlProviderNameFor(AiTask::VideoAnalysis);
        $model = (string) $this->ai->modelFor(AiTask::VideoAnalysis, $provider);
        try {
            return $this->analyzeUrlWith($provider, $model, $canonicalUrl, $title, $projectUserId, $projectId);
        } catch (AiProviderException $e) {
            $fallback = (string) config('ai.tasks.video_analysis.url_fallback_model', '');
            if ($fallback === '' || $fallback === $model || ! in_array($e->errorCode, self::FALLBACK_ON, true)) {
                throw $e;
            }
            Log::warning('clipper.url_analysis_fallback_model', ['project_id' => $projectId, 'model' => $model, 'fallback' => $fallback, 'error_code' => $e->errorCode]);
            $suggestions = $this->analyzeUrlWith($provider, $fallback, $canonicalUrl, $title, $projectUserId, $projectId);
            $this->fallbackUsed = [$fallback, $e->errorCode];

            return $suggestions;
        }
    }

    /** @return array{0: string, 1: string}|null [modèle de secours, code de l'échec du modèle principal] */
    public function fallbackUsed(): ?array
    {
        return $this->fallbackUsed;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws AiProviderException
     */
    private function analyzeUrlWith(string $provider, string $model, string $canonicalUrl, string $title, string $projectUserId, string $projectId): array
    {
        $generation = $this->log->start($projectUserId, AiTask::VideoAnalysis, $provider, $model, ['input' => 'youtube_url', 'url' => $canonicalUrl], $projectId, TranscriptAnalyzer::PROMPT_VERSION);
        try {
            $analysis = $this->analyzer->analyzeVideoUrl($canonicalUrl, $model, $title, $provider);
        } catch (AiProviderException $e) {
            $this->log->fail($generation, $e->errorCode, $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            $this->log->fail($generation, 'ERROR', $e::class);
            throw $e;
        }
        $result = $analysis['result'];
        $this->log->succeed($generation, ['suggestions' => count($analysis['suggestions']), ...$analysis['diagnostic']], $result->latencyMs, $result->inputTokens, $result->outputTokens, $result->model);

        return $analysis['suggestions'];
    }

    /** @throws AiProviderException */
    public function analyze(array $segments, array $silences, int $durationMs, string $title, string $projectUserId, string $projectId): array
    {
        $model = (string) $this->ai->modelFor(AiTask::VideoAnalysis);
        $generation = $this->log->start($projectUserId, AiTask::VideoAnalysis, $this->ai->providerFor(AiTask::VideoAnalysis)->name(), $model, ['segments' => count($segments), 'duration_ms' => $durationMs], $projectId, TranscriptAnalyzer::PROMPT_VERSION);
        try {
            $analysis = $this->analyzer->analyze($segments, $durationMs, $model, $title);
        } catch (AiProviderException $e) {
            $this->log->fail($generation, $e->errorCode, $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            $this->log->fail($generation, 'ERROR', $e::class);
            throw $e;
        }
        $result = $analysis['result'];
        $this->log->succeed($generation, ['suggestions' => count($analysis['suggestions']), ...$analysis['diagnostic']], $result->latencyMs, $result->inputTokens, $result->outputTokens, $result->model);

        return $analysis['suggestions'];
    }
}
