<?php

namespace App\Domain\Video\Clipper;

use App\Domain\AI\AiGenerationLog;
use App\Domain\AI\Enums\AiTask;
use App\Domain\AI\Exceptions\AiProviderException;
use App\Domain\Quota\DiskSpaceInsufficient;
use App\Domain\Quota\ResourceGuard;
use App\Domain\Video\Clipper\Analysis\AiClipAnalyzer;
use App\Domain\Video\Clipper\Analysis\ClipAnalyzerResolver;
use App\Domain\Video\Clipper\Transcription\Transcriber;
use App\Domain\Video\Clipper\Transcription\TranscriberResolver;
use App\Domain\Video\Clipper\Transcription\TranscriptionFailure;
use App\Domain\Video\Enums\ClipSource;
use App\Domain\Video\Enums\ClipStatus;
use App\Domain\Video\Enums\TranscriptStatus;
use App\Domain\Video\Enums\VideoProjectKind;
use App\Domain\Video\Enums\VideoProjectStatus;
use App\Domain\Video\Rendering\FfmpegVideoRenderer;
use App\Domain\Video\Rendering\MediaProbe;
use App\Domain\Video\Rendering\RenderFailure;
use App\Domain\Video\Rendering\RenderWorkspace;
use App\Models\VideoClip;
use App\Models\VideoProject;
use App\Models\VideoTranscript;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Analyse d'une vidéo source du Clipper (ADR-046, ADR-047), exécutée par AnalyzeClipSourceJob.
 *
 * pending → analyzing : FFprobe (vidéo lisible, durée) — seule étape OBLIGATOIRE ;
 * → transcribing : audio extrait par FFmpeg, pauses détectées, transcription si un moteur est
 *   disponible (whisper.cpp local ou fournisseur IA) ;
 * → detecting : passages proposés par l'IA si configurée, sinon (ou en repli) par l'analyse locale ;
 * → ready, avec ou sans propositions : le passage manuel est toujours possible.
 * « failed » est réservé à une source inutilisable (introuvable, illisible) ou au moteur absent.
 * settings.analysis décrit ce qui a réellement été fait (affiché à l'utilisateur).
 *
 * Sans vidéo source (projet créé depuis le lien seul, ADR-048) : analyse depuis l'URL YouTube par un
 * fournisseur compatible (Gemini), sinon fin propre sans proposition. Dès que la source est prête
 * (ADR-049), les passages issus du lien (déjà proposés, ou demandés à Gemini à ce moment) sont recalés
 * par ClipRecalibrator sur la durée FFprobe et la transcription ; sinon, analyse depuis la
 * transcription (IA) ou locale. Une relance réutilise la transcription de la même source.
 */
final class ClipAnalysisRunner
{
    /** Quota du fournisseur d'analyse par lien épuisé : appels suspendus (aucune requête vouée à l'échec). */
    public const URL_QUOTA_KEY = 'clipper:url-analysis-quota-exhausted';

    /** @var array<string, int> durées mesurées de l'analyse en cours (ms) */
    private array $timings = [];

    public function __construct(
        private readonly FfmpegVideoRenderer $renderer,
        private readonly MediaProbe $probe,
        private readonly AudioExtractor $audio,
        private readonly AudioSignals $signals,
        private readonly TranscriberResolver $transcribers,
        private readonly ClipAnalyzerResolver $analyzers,
        private readonly AiGenerationLog $log,
        private readonly ClipperService $clipper,
        private readonly ClipRecalibrator $recalibrator,
        private readonly ResourceGuard $resources,
    ) {}

    public function run(string $projectId): void
    {
        $project = $this->start($projectId);
        if ($project === null) {
            return; // supprimé ou déjà analysé : un job dupliqué ne fait rien
        }

        // Disque (ADR-071) réservé AVANT FFprobe, l'extraction audio et whisper ; sinon échec propre.
        try {
            $release = $this->resources->forAnalysis('clip_analysis');
        } catch (DiskSpaceInsufficient $e) {
            $this->fail($project, DiskSpaceInsufficient::CODE, $e->getMessage());

            return;
        }

        $started = microtime(true);
        $workspace = RenderWorkspace::create($project->getKey());
        $outcome = 'done';
        try {
            $this->analyze($project, $workspace);
        } catch (RenderFailure $e) {
            $outcome = $e->errorCode;
            if ($e->errorCode !== 'CANCELLED') {
                $this->fail($project, $e->errorCode, $e->getMessage());
            }
        } catch (Throwable $e) {
            $outcome = 'ANALYSIS_FAILED';
            Log::error('clipper.analysis_failed', ['project_id' => $project->getKey(), 'exception' => $e::class]);
            $this->fail($project, 'ANALYSIS_FAILED', 'L\'analyse de la vidéo a échoué de façon inattendue. Vous pouvez choisir un passage manuellement.');
        } finally {
            $workspace->delete();
            $release();
            // Durées par étape (ms) : sonde, audio, pauses, transcription, IA (lien, transcription).
            Log::info('clipper.analysis_timings', ['project_id' => $project->getKey(), 'queue' => config('video.render.queue'), 'worker_pid' => getmypid(), 'outcome' => $outcome, 'input' => $project->source_media_id !== null ? 'file' : 'youtube_url',
                ...$this->timings, 'total_ms' => (int) round((microtime(true) - $started) * 1000)]);
        }
    }

    /** Fin d'analyse sans résultat (source inutilisable, job interrompu) ; sans effet si elle est terminée. */
    public function fail(VideoProject $project, string $code, string $message): void
    {
        DB::transaction(function () use ($project, $code, $message) {
            $locked = VideoProject::query()->whereKey($project->getKey())->lockForUpdate()->first();
            if ($locked === null || ! in_array($locked->status, ClipperService::ANALYZING, true)) {
                return;
            }
            $locked->forceFill([
                'status' => VideoProjectStatus::Failed,
                'error' => mb_substr($message, 0, 1000),
                'settings' => [...($locked->settings ?? []), 'analysis_error_code' => $code],
            ])->save();
        });
    }

    private function analyze(VideoProject $project, RenderWorkspace $workspace): void
    {
        if ($project->source_media_id === null) {
            $this->analyzeFromUrl($project);

            return;
        }

        // 1. Source rendable : vidéo de l'utilisateur, lisible, durée mesurée par FFprobe.
        $lap = microtime(true);
        $this->renderer->ensureAvailable();
        $media = $this->clipper->sourceMedia($project->user_id, $project->source_media_id)
            ?? throw new RenderFailure('SOURCE_MISSING', 'La vidéo source n\'est plus disponible dans votre médiathèque.');
        $path = $this->renderer->sourcePath($media, $workspace);
        $probe = $this->probe->probe($path);
        $this->timings['probe_ms'] = $this->since($lap);
        if (! $probe->hasVideo || $probe->durationMs === null) {
            throw new RenderFailure('SOURCE_INVALID', 'Le fichier choisi n\'est pas une vidéo lisible.');
        }
        if ($probe->durationMs < ClipLimits::minClipMs()) {
            throw new RenderFailure('SOURCE_TOO_SHORT', 'La vidéo est trop courte pour en extraire un clip ('.intdiv(ClipLimits::minClipMs(), 1000).' secondes minimum).');
        }
        $durationMs = $probe->durationMs;
        $this->advance($project, VideoProjectStatus::Transcribing, ['duration_ms' => $durationMs]);

        $report = ['input' => 'file', 'transcription' => null, 'transcription_engine' => null, 'mode' => 'none', 'fallback' => null, 'notes' => []];
        $segments = [];
        $silences = [];
        $maxMinutes = (int) config('video.clipper.max_source_minutes', 120);

        if (! $probe->hasAudio) {
            $report['notes'][] = 'La vidéo n\'a pas de piste audio : aucune transcription ni détection de pauses.';
        } elseif ($durationMs > $maxMinutes * 60_000) {
            $report['notes'][] = "La vidéo dépasse {$maxMinutes} minutes : analyse automatique désactivée.";
        } else {
            // 2. Audio, pauses et transcription (facultatives) ; transcription réutilisée lors d'une
            //    relance si la source n'a pas changé (aucune nouvelle transcription inutile).
            $transcriber = $this->transcribers->resolve();
            $lap = microtime(true);
            $audioPath = $this->audio->extract($path, $workspace, $transcriber?->audioFormat() === 'wav' ? 'wav' : 'm4a');
            $this->timings['audio_ms'] = $this->since($lap);
            $lap = microtime(true);
            $silences = $this->signals->silences($audioPath, $durationMs, $workspace->path('work'));
            $this->timings['silences_ms'] = $this->since($lap);
            $reused = $this->reusableTranscript($project, $durationMs);
            if ($reused !== null) {
                [$segments, $engine] = $reused;
                $report['transcription'] = $engine === 'whisper.cpp' ? 'local' : 'ai';
                $report['transcription_engine'] = $engine;
                $report['notes'][] = 'Transcription existante réutilisée.';
            } elseif ($transcriber === null) {
                $report['notes'][] = 'Aucun moteur de transcription disponible.';
            } elseif ($durationMs > $transcriber->maxDurationMs()) {
                $report['notes'][] = 'Vidéo trop longue pour la transcription '.($transcriber->mode() === 'local' ? 'locale' : 'par IA').' : analyse sur les pauses.';
            } else {
                $lap = microtime(true);
                $segments = $this->transcribe($project, $transcriber, $audioPath, $workspace, $durationMs, $report);
                $this->timings['transcribe_ms'] = $this->since($lap);
            }
        }
        $this->advance($project, VideoProjectStatus::Detecting);

        // 3. Passages proposés depuis le lien (Gemini, déjà proposés ou demandés maintenant) : recalés
        //    sur la durée réelle et la transcription. Les horodatages de l'IA ne sont jamais définitifs.
        $lap = microtime(true);
        $fromUrl = $this->urlSuggestions($project, $report);
        $this->timings['url_ai_ms'] = $this->since($lap);
        if ($fromUrl !== []) {
            $recalibrated = $this->recalibrator->recalibrate($fromUrl, $segments, $durationMs);
            if ($recalibrated !== []) {
                $report['mode'] = 'ai';
                $report['input'] = 'youtube_url';
                $report['recalibrated'] = count($recalibrated);
                $report['fallback'] = null;
                if ($segments === []) {
                    $report['notes'][] = 'Passages recalés sur la durée réelle uniquement (aucune transcription).';
                }
                $this->finish($project, $recalibrated, $report);

                return;
            }
            $report['notes'][] = 'Les passages proposés depuis le lien ne tiennent pas dans la vidéo source.';
        }

        // 4. Passages proposés : IA si configurée, analyse locale en repli. Jamais bloquant.
        $lap = microtime(true);
        $suggestions = [];
        foreach ($this->analyzers->chain() as $analyzer) {
            if ($analyzer->requiresTranscript() && $segments === []) {
                $report['fallback'] ??= 'NO_TRANSCRIPT';

                continue;
            }
            try {
                $suggestions = $analyzer->analyze($segments, $silences, $durationMs, $project->title, $project->user_id, $project->getKey());
            } catch (AiProviderException $e) {
                $report['fallback'] = $e->errorCode;
                $report['notes'][] = $this->aiFailureNote($e);

                continue;
            }
            if ($suggestions !== []) {
                $report['mode'] = $analyzer->mode();
                break;
            }
            $report['notes'][] = $analyzer->mode() === 'ai' ? 'L\'IA n\'a trouvé aucun passage pertinent dans cette vidéo.' : 'L\'analyse locale n\'a trouvé aucun passage exploitable.';
        }
        $this->timings['ai_ms'] = $this->since($lap);

        $this->finish($project, $suggestions, $report);
    }

    /**
     * Analyse par lien réalisée avec le modèle de secours : signalée, jamais attribuée au modèle principal.
     *
     * @param  array<string, mixed>  $report
     */
    private function noteFallback(AiClipAnalyzer $analyzer, array &$report): void
    {
        if (($used = $analyzer->fallbackUsed()) !== null) {
            $report['fallback_model'] = $used[0];
            $report['notes'][] = "Analyse du lien réalisée avec le modèle de secours {$used[0]} (modèle principal indisponible : {$used[1]}).";
        }
    }

    /**
     * Note affichée quand l'analyse IA n'aboutit pas : passages proposés mais tous écartés (raisons
     * précises), réponse interrompue ou illisible, ou fournisseur indisponible — jamais confondus avec
     * « aucun passage pertinent ».
     */
    private function aiFailureNote(AiProviderException $e): string
    {
        return $e->errorCode === 'NO_VALID_CLIPS'
            ? ucfirst($e->getMessage()).'.'
            : 'Analyse par IA indisponible ('.$e->getMessage().').';
    }

    /**
     * Analyse depuis le lien seul (ADR-048) : aucune vidéo n'est téléchargée ; le fournisseur compatible
     * regarde la vidéo publique. Sans fournisseur compatible : fin propre, la source reste à joindre.
     */
    private function analyzeFromUrl(VideoProject $project): void
    {
        $report = ['input' => 'youtube_url', 'transcription' => null, 'transcription_engine' => null, 'mode' => 'none', 'fallback' => null, 'notes' => []];

        $analyzer = $this->analyzers->urlAnalyzer();
        $suggestions = [];
        $this->advance($project, VideoProjectStatus::Detecting);
        if ($analyzer !== null && Cache::has($this->urlQuotaKey())) {
            $report['fallback'] = 'QUOTA_EXCEEDED';
            $report['notes'][] = 'Analyse du lien suspendue : le quota du fournisseur IA est épuisé (nouvel essai plus tard). Les passages seront proposés depuis la vidéo source.';
        } elseif ($analyzer === null) {
            $report['notes'][] = 'L\'analyse directe depuis le lien YouTube nécessite un fournisseur IA compatible (Gemini), non configuré. Ajoutez la vidéo source : elle sera analysée localement.';
        } else {
            try {
                $suggestions = $analyzer->analyzeUrl((string) $project->source_url, $project->title, $project->user_id, $project->getKey());
                $this->noteFallback($analyzer, $report);
                $report['mode'] = $suggestions !== [] ? 'ai' : 'none';
                if ($suggestions === []) {
                    $report['notes'][] = 'L\'IA a analysé la vidéo et n\'y a trouvé aucun passage pertinent.';
                }
            } catch (AiProviderException $e) {
                $this->rememberQuota($e);
                $report['fallback'] = $e->errorCode;
                $report['notes'][] = $this->aiFailureNote($e);
            }
        }
        $this->finish($project, $suggestions, $report);
    }

    /**
     * Passages issus du lien : ceux déjà proposés (analyse précédente depuis l'URL), sinon une analyse
     * Gemini maintenant si le fournisseur est disponible. Échec de l'IA : noté, jamais bloquant.
     *
     * @param  array<string, mixed>  $report
     * @return list<array{start_ms: int, end_ms: int, title: string, topic: string, reason: string, score: int}>
     */
    private function urlSuggestions(VideoProject $project, array &$report): array
    {
        if (($project->settings['analysis']['input'] ?? null) === 'youtube_url') {
            $existing = VideoClip::query()->where('video_project_id', $project->getKey())->where('status', ClipStatus::Suggested->value)->get();
            if ($existing->isNotEmpty()) {
                return $existing->map(fn (VideoClip $clip) => [
                    'start_ms' => $clip->start_ms, 'end_ms' => $clip->end_ms, 'title' => (string) $clip->title,
                    'topic' => (string) $clip->topic, 'reason' => (string) $clip->reason, 'score' => (float) $clip->score,
                    'hook' => $clip->hook, 'confidence' => $clip->confidence, 'score_breakdown' => $clip->score_breakdown,
                ])->values()->all();
            }
        }
        $analyzer = $this->analyzers->urlAnalyzer();
        if ($analyzer === null || ! is_string($project->source_url) || $project->source_url === '') {
            return [];
        }
        if (Cache::has($this->urlQuotaKey())) {
            $report['notes'][] = 'Analyse du lien suspendue (quota du fournisseur IA épuisé) : passages proposés depuis la vidéo source.';

            return [];
        }
        try {
            $suggestions = $analyzer->analyzeUrl($project->source_url, $project->title, $project->user_id, $project->getKey());
            $this->noteFallback($analyzer, $report);

            return $suggestions;
        } catch (AiProviderException $e) {
            $this->rememberQuota($e);
            $report['fallback'] = $e->errorCode;
            $report['notes'][] = 'Analyse du lien par IA indisponible ('.$e->getMessage().') : passages proposés depuis la vidéo source.';

            return [];
        }
    }

    /**
     * Quota épuisé (y compris le modèle de secours) : l'analyse par lien est suspendue 30 minutes au lieu
     * d'être retentée à chaque projet (7 échecs identiques constatés le 2026-10-13).
     */
    private function rememberQuota(AiProviderException $e): void
    {
        if ($e->errorCode === 'QUOTA_EXCEEDED') {
            Cache::put($this->urlQuotaKey(), true, now()->addMinutes(30));
            Log::warning('clipper.url_analysis_suspended', ['minutes' => 30]);
        }
    }

    /**
     * Suspension liée à la configuration exacte de l'analyse par lien (fournisseur, modèles, modèle de secours) :
     * un modèle de secours ajouté ou un autre modèle choisi est essayé aussitôt.
     */
    private function urlQuotaKey(): string
    {
        return self::URL_QUOTA_KEY.':'.hash('sha256', (string) json_encode([config('ai.tasks.video_analysis'), config('ai.providers.gemini.models')]));
    }

    private function since(float $lap): int
    {
        return (int) round((microtime(true) - $lap) * 1000);
    }

    /**
     * Transcription prête de la même source (relance) : segments bornés à la durée et moteur.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}|null
     */
    private function reusableTranscript(VideoProject $project, int $durationMs): ?array
    {
        if (($project->settings['transcript_media_id'] ?? null) !== $project->source_media_id) {
            return null;
        }
        $transcript = VideoTranscript::query()->where('video_project_id', $project->getKey())->where('status', TranscriptStatus::Ready->value)->first();
        $segments = array_values(array_filter((array) ($transcript?->segments ?? []), fn ($s) => is_array($s) && isset($s['start_ms'], $s['end_ms'], $s['text']) && $s['end_ms'] <= $durationMs));

        return $segments !== [] ? [$segments, (string) $transcript->provider] : null;
    }

    /**
     * Fin d'analyse : remplace les propositions (null = conserver celles en place) et passe en ready.
     *
     * @param  list<array{start_ms: int, end_ms: int, title: string, topic: string, reason: string, score: int|float, hook?: ?string, confidence?: ?int, score_breakdown?: ?array<string, int>}>|null  $suggestions
     * @param  array<string, mixed>  $report
     */
    private function finish(VideoProject $project, ?array $suggestions, array $report): void
    {
        DB::transaction(function () use ($project, $suggestions, $report) {
            $locked = $this->locked($project);
            if ($suggestions !== null) {
                VideoClip::query()->where('video_project_id', $locked->getKey())->where('status', ClipStatus::Suggested->value)->delete();
            }
            foreach ($suggestions ?? [] as $suggestion) {
                $clip = new VideoClip([
                    'title' => $suggestion['title'],
                    'topic' => $suggestion['topic'] ?: null,
                    'reason' => $suggestion['reason'] ?: null,
                    'start_ms' => $suggestion['start_ms'],
                    'end_ms' => $suggestion['end_ms'],
                    'aspect_ratio' => $locked->aspect_ratio,
                    'subtitles_enabled' => false,
                ]);
                $clip->video_project_id = $locked->getKey();
                $clip->forceFill([
                    'source' => $report['mode'] === 'ai' ? ClipSource::Ai : ClipSource::Auto,
                    'status' => ClipStatus::Suggested,
                    'score' => $suggestion['score'],
                    // Clip Intelligence Engine (ADR-051) : absents pour l'analyse locale (heuristique).
                    'hook' => isset($suggestion['hook']) && $suggestion['hook'] !== '' ? mb_substr((string) $suggestion['hook'], 0, 255) : null,
                    'confidence' => $suggestion['confidence'] ?? null,
                    'score_breakdown' => $suggestion['score_breakdown'] ?? null,
                ])->save();
            }
            $settings = $locked->settings ?? [];
            unset($settings['analysis_error_code']);
            $locked->forceFill(['status' => VideoProjectStatus::Ready, 'error' => null, 'settings' => [...$settings, 'analysis' => $report]])->save();
        });
    }

    /**
     * Transcription facultative, tracée dans ai_generations ; un échec n'interrompt jamais l'analyse.
     *
     * @param  array<string, mixed>  $report
     * @return list<array{start_ms: int, end_ms: int, text: string}>
     */
    private function transcribe(VideoProject $project, Transcriber $transcriber, string $audioPath, RenderWorkspace $workspace, int $durationMs, array &$report): array
    {
        $generation = $this->log->start($project->user_id, AiTask::Transcription, $transcriber->engine(), $transcriber->model(), ['duration_ms' => $durationMs, 'mode' => $transcriber->mode()], $project->getKey());
        try {
            $result = $transcriber->transcribe($audioPath, $workspace);
        } catch (AiProviderException|TranscriptionFailure $e) {
            $this->log->fail($generation, $e->errorCode, $e->getMessage());
            $report['notes'][] = 'Transcription impossible ('.$e->getMessage().').';

            return [];
        }
        $this->log->succeed($generation, ['language' => $result->language, 'segments' => count($result->segments)], $result->latencyMs);

        // Segments (et mots horodatés s'ils existent) bornés à la vidéo : jamais de temps inventé.
        $segments = array_values(array_filter(array_map(
            fn ($s) => array_filter([
                'start_ms' => max(0, $s['start_ms']),
                'end_ms' => min($durationMs, $s['end_ms']),
                'text' => $s['text'],
                'words' => isset($s['words']) ? array_values(array_filter((array) $s['words'], fn ($w) => is_array($w) && ($w[1] ?? PHP_INT_MAX) <= $durationMs)) : null,
            ], fn ($v) => $v !== null),
            $result->segments,
        ), fn ($s) => $s['end_ms'] > $s['start_ms']));
        if ($segments === []) {
            $report['notes'][] = 'Aucune parole détectée dans la vidéo.';

            return [];
        }

        VideoTranscript::query()->where('video_project_id', $project->getKey())->delete();
        VideoTranscript::query()->create([
            'video_project_id' => $project->getKey(),
            'language' => mb_substr($result->language ?: 'und', 0, 10),
            'provider' => mb_substr($transcriber->engine(), 0, 32),
            'status' => TranscriptStatus::Ready,
            'segments' => $segments,
            'full_text' => $result->text,
        ]);
        $report['transcription'] = $transcriber->mode();
        $report['transcription_engine'] = $transcriber->engine();
        $settings = VideoProject::query()->whereKey($project->getKey())->value('settings');
        $settings = is_string($settings) ? (array) json_decode($settings, true) : (array) $settings;
        $this->advance($project, VideoProjectStatus::Transcribing, [
            'language' => mb_substr((string) $result->language, 0, 10) ?: null,
            // Source transcrite : une relance réutilise cette transcription tant que la source est la même.
            'settings' => [...$settings, 'transcript_media_id' => $project->source_media_id],
        ]);

        return $segments;
    }

    private function start(string $projectId): ?VideoProject
    {
        return DB::transaction(function () use ($projectId) {
            $project = VideoProject::query()->whereKey($projectId)->where('kind', VideoProjectKind::Clipping->value)->lockForUpdate()->first();
            if ($project === null || $project->status !== VideoProjectStatus::Pending) {
                return null;
            }
            // Attente en file : de la mise en attente du projet (création, source liée, relance) au démarrage.
            $this->timings = ['queued_ms' => max(0, (int) $project->updated_at?->diffInMilliseconds(now()))];
            $project->forceFill(['status' => VideoProjectStatus::Analyzing, 'error' => null])->save();

            return $project;
        });
    }

    /** @param array<string, mixed> $attributes */
    private function advance(VideoProject $project, VideoProjectStatus $status, array $attributes = []): void
    {
        DB::transaction(function () use ($project, $status, $attributes) {
            $this->locked($project)->forceFill(['status' => $status, ...$attributes])->save();
        });
    }

    /** Projet toujours en cours d'analyse, verrouillé ; supprimé entre-temps : arrêt silencieux. */
    private function locked(VideoProject $project): VideoProject
    {
        $locked = VideoProject::query()->whereKey($project->getKey())->lockForUpdate()->first();
        if ($locked === null || ! in_array($locked->status, ClipperService::ANALYZING, true)) {
            throw RenderFailure::cancelled();
        }

        return $locked;
    }
}
