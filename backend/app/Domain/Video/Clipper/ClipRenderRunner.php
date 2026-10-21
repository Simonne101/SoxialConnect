<?php

namespace App\Domain\Video\Clipper;

use App\Domain\Quota\DiskSpaceInsufficient;
use App\Domain\Quota\QuotaExceeded;
use App\Domain\Quota\ResourceGuard;
use App\Domain\Video\Clipper\Planning\ClipPlanner;
use App\Domain\Video\Clipper\Rendering\RemotionRenderer;
use App\Domain\Video\Enums\ClipStatus;
use App\Domain\Video\Enums\VideoProjectKind;
use App\Domain\Video\Enums\VideoProjectStatus;
use App\Domain\Video\Rendering\AudioCheck;
use App\Domain\Video\Rendering\FfmpegVideoRenderer;
use App\Domain\Video\Rendering\MediaProbe;
use App\Domain\Video\Rendering\RenderedVideo;
use App\Domain\Video\Rendering\RenderedVideoPublisher;
use App\Domain\Video\Rendering\RenderFailure;
use App\Domain\Video\Rendering\RenderWorkspace;
use App\Models\Media;
use App\Models\VideoClip;
use App\Models\VideoProject;
use App\Models\VideoTranscript;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rendu d'un clip du Clipper (ADR-046), exécuté par RenderClipJob avec le moteur FFmpeg existant :
 * 1. limite de 60 s revérifiée depuis la base (jamais depuis la requête) avant tout traitement ;
 * 2. source revérifiée (propriétaire, vidéo utilisable, extrait dans la durée réelle FFprobe) ;
 * 3. spécification soxial.render_spec.v1 à un seul élément vidéo (recadrage « cover » au format du
 *    clip, son d'origine) → FfmpegVideoRenderer::produce (commande, FFmpeg, validation FFprobe) ;
 * 4. durée RÉELLE du fichier relue par FFprobe : au-delà de 60 s, le clip échoue et n'est pas publié ;
 * 5. publication dans la médiathèque (RenderedVideoPublisher) → rendered.
 * Étapes visibles (render_stage) : preparing → rendering → validating → publishing ; « Clip prêt »
 * n'est affiché qu'une fois le fichier produit, validé par FFprobe ET publié.
 * Sous-titres (facultatifs, ADR-047) : piste « text » construite depuis la transcription.
 *
 * Composition (ADR-052, video.clipper.composer = remotion) : le passage est découpé et recadré par
 * FFmpeg SANS sous-titres, puis ClipPlanner produit le RenderPlan et RemotionRenderer compose accroche
 * et sous-titres (modèle clean-short) ; le MP4 final est revalidé par FFprobe (dimensions, durée, son)
 * avant publication. Composition désactivée ou non installée : moteur FFmpeg seul (sous-titres drawtext).
 * L'annulation est vérifiée avant chaque étape coûteuse et pendant les processus FFmpeg et Remotion,
 * qui sont réellement arrêtés ; un clip annulé n'est jamais publié.
 */
final class ClipRenderRunner
{
    /** Avancement visible d'un rendu en cours (sous-étape et pourcentage), sans colonne en base. */
    public const PROGRESS_KEY = 'video:clip-progress:';

    /** Crête en dessous de laquelle un passage est considéré comme silencieux (dBFS). */
    private const SILENCE_DB = -50.0;

    /** Perte de niveau moyen tolérée entre la source et le clip (réencodage, mixage) avant refus (dB). */
    private const LOUDNESS_TOLERANCE_DB = 6.0;

    /** Écart toléré entre la durée de la piste audio et celle de l'image (ms). */
    private const AUDIO_SYNC_TOLERANCE_MS = 250;

    /** @var array<string, int> durées mesurées du rendu en cours (ms) */
    private array $timings = [];

    private string $currentStep = 'preparing';

    public function __construct(
        private readonly FfmpegVideoRenderer $renderer,
        private readonly MediaProbe $probe,
        private readonly RenderedVideoPublisher $publisher,
        private readonly ClipperService $clipper,
        private readonly ClipPlanner $planner,
        private readonly RemotionRenderer $remotion,
        private readonly AudioCheck $audio,
        private readonly ResourceGuard $resources,
    ) {}

    public function run(string $clipId): void
    {
        $clip = $this->start($clipId);
        if ($clip === null) {
            return; // supprimé, déjà traité ou job dupliqué
        }

        // Quotas (ADR-071) : stockage de l'utilisateur et disque réservés AVANT FFmpeg et Remotion ; sinon le
        // clip échoue proprement (le projet libère sa place de projet actif) et rien n'est lancé.
        try {
            $release = $this->resources->forRender((string) $clip->project->user_id, 'clip_render');
        } catch (QuotaExceeded $e) {
            $this->fail($clip, $e->errorCode, $e->getMessage());

            return;
        } catch (DiskSpaceInsufficient $e) {
            $this->fail($clip, DiskSpaceInsufficient::CODE, $e->getMessage());

            return;
        }

        $started = microtime(true);
        $workspace = RenderWorkspace::create($clip->getKey());
        $outcome = 'rendered';
        try {
            $this->render($clip, $workspace);
        } catch (RenderFailure $e) {
            $outcome = $e->errorCode;
            if ($e->errorCode !== 'CANCELLED') {
                $this->fail($clip, $e->errorCode, $e->getMessage());
            }
        } catch (Throwable $e) {
            $outcome = 'RENDER_FAILED';
            Log::error('clipper.render_failed', ['clip_id' => $clip->getKey(), 'step' => $this->currentStep, 'exception' => $e::class]);
            $this->fail($clip, 'RENDER_FAILED', 'La génération du clip a échoué de façon inattendue.');
        } finally {
            $workspace->delete();
            $release();
            Cache::forget(self::PROGRESS_KEY.$clip->getKey());
            // Durées par étape (ms), étape atteinte et issue : diagnostic des lenteurs et des échecs.
            Log::info('clipper.render_timings', ['clip_id' => $clip->getKey(), 'queue' => config('video.render.queue'), 'worker_pid' => getmypid(), 'outcome' => $outcome, 'step' => $this->currentStep,
                'clip_ms' => $clip->end_ms - $clip->start_ms, ...$this->timings, 'total_ms' => (int) round((microtime(true) - $started) * 1000)]);
        }
    }

    public function fail(VideoClip $clip, string $code, string $message): void
    {
        DB::transaction(function () use ($clip, $code, $message) {
            $locked = VideoClip::query()->whereKey($clip->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->status !== ClipStatus::Rendering) {
                return;
            }
            $locked->forceFill(['status' => ClipStatus::Failed, 'render_stage' => null, 'error_code' => $code, 'error_message' => mb_substr($message, 0, 500), 'finished_at' => now()])->save();
        });
    }

    private function render(VideoClip $clip, RenderWorkspace $workspace): void
    {
        $project = VideoProject::query()->whereKey($clip->video_project_id)->where('kind', VideoProjectKind::Clipping->value)->first()
            ?? throw new RenderFailure('SOURCE_MISSING', 'Le projet de ce clip a été supprimé.');

        // 1. Règle des 60 s, depuis les valeurs enregistrées.
        $violation = ClipLimits::violation($clip->start_ms, $clip->end_ms, $project->duration_ms);
        if ($violation !== null) {
            throw new RenderFailure('INVALID_DURATION', $violation);
        }
        $durationMs = $clip->end_ms - $clip->start_ms;

        // 2. Source revérifiée.
        $lap = microtime(true);
        $this->renderer->ensureAvailable();
        $media = $this->clipper->sourceMedia($project->user_id, $project->source_media_id)
            ?? throw new RenderFailure('SOURCE_MISSING', 'La vidéo source n\'est plus disponible dans votre médiathèque.');
        $path = $this->renderer->sourcePath($media, $workspace);
        $source = $this->probe->probe($path);
        if (! $source->hasVideo) {
            throw new RenderFailure('SOURCE_INVALID', 'Le fichier source n\'est pas une vidéo lisible.');
        }
        if ($source->durationMs !== null && $clip->end_ms > $source->durationMs + 50) {
            throw new RenderFailure('SOURCE_RANGE', 'Le passage dépasse la fin de la vidéo source.');
        }
        $this->timings['source_ms'] = $this->since($lap);

        // Annulation coopérative (ADR-051) : vérifiée avant et pendant chaque étape coûteuse.
        $shouldCancel = fn () => ! VideoClip::query()->whereKey($clip->getKey())->where('status', ClipStatus::Rendering->value)->exists()
            || ! VideoProject::query()->whereKey($project->getKey())->where('status', '!=', VideoProjectStatus::Cancelled->value)->exists();
        if ($shouldCancel()) {
            throw RenderFailure::cancelled();
        }

        // 3. Rendu. Composition Remotion (ADR-052) : FFmpeg découpe et recadre le passage SANS
        //    sous-titres, puis Remotion compose accroche et sous-titres sur cette vidéo. Repli : moteur
        //    FFmpeg seul (sous-titres drawtext), si la composition est désactivée ou non installée.
        $this->stage($clip, 'rendering');
        $compose = config('video.clipper.composer') === 'remotion' && $this->remotion->isAvailable();
        $this->progress($clip, 'cutting');
        $lap = microtime(true);
        $video = $this->renderer->produce(
            $this->spec($clip, $media, $durationMs, $project, withSubtitles: ! $compose),
            [$media->getKey() => $path],
            [$media->getKey() => $source->hasAudio],
            $workspace,
            onProgress: fn (int $percent) => null,
            shouldCancel: $shouldCancel,
        );
        $this->timings['cut_ms'] = $this->since($lap);
        $composition = ['composer' => 'ffmpeg'];
        if ($compose) {
            if ($shouldCancel()) {
                throw RenderFailure::cancelled();
            }
            $plan = $this->planner->plan(
                ['start_ms' => $clip->start_ms, 'end_ms' => $clip->end_ms, 'hook' => $clip->hook],
                $clip->aspect_ratio,
                $clip->subtitles_enabled ? $this->segments($project) : [],
                subtitles: (bool) $clip->subtitles_enabled,
                showHook: (bool) config('video.clipper.show_hook', true),
            );
            $this->progress($clip, 'composing', 0);
            $lap = microtime(true);
            try {
                $video = $this->remotion->render($plan, $video->path, $workspace, $shouldCancel, expectAudio: $video->probe->hasAudio,
                    onProgress: fn (int $percent) => $this->progress($clip, 'composing', $percent));
                $this->timings['compose_ms'] = $this->since($lap);
                $composition = ['composer' => 'remotion', 'template' => $plan->template, 'hook' => $plan->hook !== null, 'captions' => count($plan->captions), 'crop' => $plan->crop];
            } catch (RenderFailure $e) {
                // Échec de la composition (jamais une annulation) : repli FFmpeg seul si activé — sous-titres
                // incrustés par FFmpeg, sans accroche ; le repli est tracé dans les métadonnées du média.
                if ($e->errorCode === 'CANCELLED' || ! config('video.clipper.remotion_fallback', true) || $shouldCancel()) {
                    throw $e;
                }
                $this->timings['compose_failed_ms'] = $this->since($lap);
                Log::warning('clipper.remotion_fallback_ffmpeg', ['clip_id' => $clip->getKey(), 'error_code' => $e->errorCode]);
                $this->progress($clip, 'fallback');
                $lap = microtime(true);
                $video = $this->renderer->produce(
                    $this->spec($clip, $media, $durationMs, $project, withSubtitles: true),
                    [$media->getKey() => $path],
                    [$media->getKey() => $source->hasAudio],
                    $workspace,
                    onProgress: fn (int $percent) => null,
                    shouldCancel: $shouldCancel,
                );
                $composition = ['composer' => 'ffmpeg', 'fallback_from' => 'remotion', 'fallback_reason' => $e->errorCode];
                $this->timings['fallback_ms'] = $this->since($lap);
            }
        }
        if ($shouldCancel()) {
            throw RenderFailure::cancelled();
        }

        // 4. Durée réelle du fichier produit (FFprobe).
        $this->stage($clip, 'validating');
        $this->progress($clip, 'validating');
        if ($video->probe->durationMs === null || $video->probe->durationMs > ClipLimits::MAX_CLIP_MS) {
            Log::warning('clipper.output_too_long', ['clip_id' => $clip->getKey(), 'duration_ms' => $video->probe->durationMs]);
            throw new RenderFailure('OUTPUT_TOO_LONG', 'Le clip produit dépasse 60 secondes : il n\'a pas été publié.');
        }
        $lap = microtime(true);
        $audio = $this->validateAudio($clip, $path, $source->hasAudio, $video, $durationMs);
        $this->timings['audio_check_ms'] = $this->since($lap);

        // 5. Publication.
        $this->stage($clip, 'publishing');
        $this->progress($clip, 'publishing');
        $lap = microtime(true);
        $metadata = [
            'source' => 'youtube_clipper',
            'video_clip_id' => $clip->getKey(),
            'video_project_id' => $project->getKey(),
            'youtube_url' => $project->source_url,
            'clip_start_ms' => $clip->start_ms,
            'clip_end_ms' => $clip->end_ms,
            'subtitles' => (bool) $clip->subtitles_enabled,
            'composition' => $composition,
            'audio' => $audio,
        ];
        $this->publisher->publish($project->user_id, $clip->title, $video, $metadata, function (Media $output) use ($clip) {
            $locked = VideoClip::query()->whereKey($clip->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->status !== ClipStatus::Rendering) {
                return false; // clip supprimé ou relancé entre-temps : rien n'est publié
            }
            $output->save();
            $locked->forceFill(['status' => ClipStatus::Rendered, 'render_stage' => null, 'output_media_id' => $output->getKey(), 'finished_at' => now(), 'error_code' => null, 'error_message' => null])->save();

            return true;
        });
        $this->timings['publish_ms'] = $this->since($lap);
    }

    /**
     * Son du clip vérifié AVANT publication (FFprobe + mesure du niveau) : une source avec du son ne doit jamais
     * donner un clip muet, inaudible ou désynchronisé. Passage réellement silencieux dans la source, ou source
     * sans piste audio : publié, mais signalé (métadonnées « audio ») — jamais un son ajouté artificiellement.
     *
     * @return array<string, mixed>
     *
     * @throws RenderFailure
     */
    private function validateAudio(VideoClip $clip, string $sourcePath, bool $sourceHasAudio, RenderedVideo $video, int $durationMs): array
    {
        if (! $sourceHasAudio) {
            return ['status' => 'source_without_audio'];
        }
        if (! $video->probe->hasAudio) {
            throw new RenderFailure('AUDIO_MISSING', 'Le clip produit n\'a pas de son alors que la vidéo source en contient : il n\'a pas été publié.');
        }
        $streams = $this->audio->streamDurations($video->path);
        if ($streams['audio'] === null || $streams['video'] === null || abs($streams['audio'] - $streams['video']) > self::AUDIO_SYNC_TOLERANCE_MS) {
            throw new RenderFailure('AUDIO_DESYNC', 'La piste audio du clip ne correspond pas à la durée de l\'image (désynchronisation) : il n\'a pas été publié.');
        }
        $reference = $this->audio->loudness($sourcePath, $clip->start_ms, $durationMs);
        $output = $this->audio->loudness($video->path);
        if ($reference === null || $output === null) {
            throw new RenderFailure('AUDIO_CHECK_FAILED', 'Le son du clip n\'a pas pu être vérifié : il n\'a pas été publié.');
        }
        $levels = ['source_mean_db' => $reference['mean'], 'source_max_db' => $reference['max'], 'output_mean_db' => $output['mean'], 'output_max_db' => $output['max']];
        if ($reference['max'] <= self::SILENCE_DB) {
            return ['status' => 'source_silent', ...$levels]; // passage muet dans la source elle-même
        }
        if ($output['max'] <= self::SILENCE_DB || $output['mean'] < $reference['mean'] - self::LOUDNESS_TOLERANCE_DB) {
            Log::warning('clipper.audio_lost', ['clip_id' => $clip->getKey(), ...$levels]);

            throw new RenderFailure('AUDIO_SILENT', sprintf('Le son du clip est absent ou nettement plus faible que celui de la vidéo source (%.1f dB au lieu de %.1f dB) : il n\'a pas été publié.', $output['mean'], $reference['mean']));
        }

        return ['status' => 'ok', ...$levels];
    }

    /**
     * Sous-étape visible du rendu (découpe FFmpeg, composition Remotion avec pourcentage réel, validation,
     * publication) : cache partagé, lu par l'API pendant le suivi ; jamais en base (aucune migration).
     */
    private function progress(VideoClip $clip, string $step, ?int $percent = null): void
    {
        $this->currentStep = $step;
        Cache::put(self::PROGRESS_KEY.$clip->getKey(), ['step' => $step, 'percent' => $percent], 3600);
    }

    private function since(float $lap): int
    {
        return (int) round((microtime(true) - $lap) * 1000);
    }

    /**
     * Segments horodatés de la transcription prête du projet (mots compris), filtrés : une valeur
     * inattendue donne « aucun sous-titre » plutôt qu'un rendu faux.
     *
     * @return list<array{start_ms: int, end_ms: int, text: string, words?: list<array{0: int, 1: int, 2: string}>}>
     */
    private function segments(VideoProject $project): array
    {
        $segments = (array) (VideoTranscript::query()->where('video_project_id', $project->getKey())->where('status', 'ready')->value('segments') ?? []);
        if (is_string($segments[0] ?? null)) {
            return [];
        }

        return array_values(array_filter($segments, fn ($s) => is_array($s) && isset($s['start_ms'], $s['end_ms'], $s['text'])));
    }

    /** Étape visible du rendu (sans effet si le clip n'est plus en cours). */
    private function stage(VideoClip $clip, string $stage): void
    {
        VideoClip::query()->whereKey($clip->getKey())->where('status', ClipStatus::Rendering->value)->update(['render_stage' => $stage]);
    }

    /**
     * Spécification au format du Video Studio : un élément vidéo couvrant tout le clip, et une piste
     * de sous-titres si demandée (transcription du projet).
     *
     * @return array<string, mixed>
     */
    private function spec(VideoClip $clip, Media $media, int $durationMs, VideoProject $project, bool $withSubtitles = true): array
    {
        $ratio = $clip->aspect_ratio->value;
        $size = (array) config("video.formats.{$ratio}");
        $subtitles = [];
        if ($clip->subtitles_enabled && $withSubtitles) {
            $subtitles = SubtitleTrack::items(
                $this->segments($project),
                $clip->start_ms,
                $clip->end_ms,
                (int) $size['width'],
                (int) $size['height'],
                (array) ($clip->subtitle_style ?? []),
            );
        }

        $spec = [
            'schema' => 'soxial.render_spec.v1',
            'project' => [
                'id' => $clip->getKey(),
                'aspect_ratio' => $ratio,
                'width' => (int) $size['width'],
                'height' => (int) $size['height'],
                'fps' => (int) config('video.fps.default', 30),
                'duration_ms' => $durationMs,
            ],
            'scenes' => [],
            'tracks' => [[
                'id' => 'clip',
                'type' => 'video',
                'position' => 0,
                'muted' => false,
                'hidden' => false,
                'items' => [[
                    'id' => $clip->getKey(),
                    'type' => 'video',
                    'media_id' => $media->getKey(),
                    'start_ms' => 0,
                    'duration_ms' => $durationMs,
                    'source_start_ms' => $clip->start_ms,
                    'source_duration_ms' => $durationMs,
                    // Volume en POURCENTAGE (0-100), comme tout le moteur (FfmpegCommandBuilder, Video Studio). La
                    // valeur 1 (= 1 %) atténuait le son de 40 dB : clips inaudibles (corrigé le 2026-10-13).
                    'properties' => ['fit' => 'cover', 'volume' => 100],
                    'transitions' => null,
                ]],
            ]],
        ];
        if ($subtitles !== []) {
            $spec['tracks'][] = ['id' => 'subtitles', 'type' => 'text', 'position' => 1, 'muted' => false, 'hidden' => false, 'items' => $subtitles];
        }

        return $spec;
    }

    private function start(string $clipId): ?VideoClip
    {
        return DB::transaction(function () use ($clipId) {
            $clip = VideoClip::query()->whereKey($clipId)->lockForUpdate()->first();
            if ($clip === null || $clip->status !== ClipStatus::Rendering || $clip->started_at !== null) {
                return null;
            }
            // Attente en file : de la demande (dernière mise à jour du clip) au démarrage par le worker.
            $this->timings = ['queued_ms' => max(0, (int) $clip->updated_at?->diffInMilliseconds(now()))];
            $this->currentStep = 'preparing';
            $clip->forceFill(['started_at' => now(), 'render_stage' => 'preparing'])->save();

            return $clip;
        });
    }
}
