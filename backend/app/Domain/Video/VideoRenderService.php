<?php

namespace App\Domain\Video;

use App\Domain\Video\Enums\RenderStatus;
use App\Domain\Video\Enums\VideoProjectStatus;
use App\Domain\Video\Exceptions\VideoConflict;
use App\Domain\Video\Rendering\FfmpegBinary;
use App\Domain\Video\Rendering\RenderedVideo;
use App\Domain\Video\Rendering\RenderedVideoPublisher;
use App\Domain\Video\Rendering\RenderFailure;
use App\Domain\Video\Support\VideoProjectLock;
use App\Jobs\RenderVideoJob;
use App\Models\Media;
use App\Models\VideoProject;
use App\Models\VideoRenderJob;
use App\Models\VideoTimelineItem;
use Illuminate\Support\Facades\DB;

/**
 * Rendus MP4 (ADR-044, ADR-045) :
 * - demande : la timeline est figée (spec, empreinte canonique) ; si un rendu identique a déjà
 *   réussi et que sa vidéo est disponible, il est renvoyé tel quel (aucun nouveau calcul) ; sinon
 *   le rendu « queued » est créé et RenderVideoJob poussé sur la file « video » (redis-video) ;
 * - exécution (RenderVideoJob + FfmpegVideoRenderer) : queued → rendering → succeeded | failed ;
 *   annulation possible tant que le rendu est actif (FFmpeg arrêté par le moteur) ;
 * - publication : le MP4 validé devient un média de la médiathèque (stockage privé, URL signée),
 *   uniquement si le rendu est toujours « rendering » (jamais après une annulation).
 */
final class VideoRenderService
{
    public function __construct(
        private readonly FfmpegBinary $ffmpeg,
        private readonly RenderedVideoPublisher $publisher,
    ) {}

    /** Moteur utilisable : FFmpeg (production) et FFprobe (analyse, validation). */
    public function rendererAvailable(): bool
    {
        return $this->ffmpeg->isAvailable() && $this->ffmpeg->isProbeAvailable();
    }

    public function request(VideoProject $project, ?int $expectedVersion = null): VideoRenderJob
    {
        return DB::transaction(function () use ($project, $expectedVersion) {
            $locked = VideoProjectLock::acquire($project, $expectedVersion);
            $active = VideoRenderJob::query()->where('video_project_id', $locked->getKey())
                ->whereIn('status', [RenderStatus::Queued->value, RenderStatus::Rendering->value])->exists();
            if ($active) {
                throw VideoConflict::renderActive();
            }

            $spec = $this->spec($locked);
            $hash = self::hashSpec($spec);

            // Même timeline déjà rendue avec succès et vidéo encore disponible : pas de nouveau calcul.
            $done = VideoRenderJob::query()->with('outputMedia')
                ->where('video_project_id', $locked->getKey())->where('spec_hash', $hash)
                ->where('status', RenderStatus::Succeeded->value)->latest()->first();
            if ($done !== null && $done->outputMedia !== null && $done->outputMedia->isUsable()) {
                return $done;
            }

            $render = new VideoRenderJob;
            $render->forceFill([
                'video_project_id' => $locked->getKey(),
                'user_id' => $locked->user_id,
                'status' => RenderStatus::Queued,
                'progress' => 0,
                'spec' => $spec,
                'spec_hash' => $hash,
                'renderer' => config('video.renderer'),
                'aspect_ratio' => $locked->aspect_ratio,
                'width' => $locked->width,
                'height' => $locked->height,
                'fps' => $locked->fps,
            ])->save();

            $locked->forceFill(['status' => VideoProjectStatus::Rendering, 'last_render_id' => $render->getKey()])->save();

            RenderVideoJob::dispatch($render->getKey())->afterCommit();

            return $render;
        });
    }

    /** Prise en charge par le worker : queued → rendering, sous verrou. Null si le rendu n'est plus en file. */
    public function start(string $renderId): ?VideoRenderJob
    {
        return DB::transaction(function () use ($renderId) {
            $render = VideoRenderJob::query()->whereKey($renderId)->lockForUpdate()->first();
            if ($render === null || $render->status !== RenderStatus::Queued) {
                return null; // supprimé, annulé ou déjà traité (job dupliqué ou relancé)
            }
            $render->forceFill(['status' => RenderStatus::Rendering, 'started_at' => now(), 'progress' => 0])->save();

            return $render;
        });
    }

    /** Progression indicative (0-99) pendant l'encodage ; jamais 100 avant la validation du fichier. */
    public function progress(VideoRenderJob $render, int $percent): void
    {
        VideoRenderJob::query()->whereKey($render->getKey())
            ->where('status', RenderStatus::Rendering->value)
            ->where('progress', '<', min(99, $percent))
            ->update(['progress' => min(99, $percent), 'updated_at' => now()]);
    }

    public function isCancelled(VideoRenderJob $render): bool
    {
        // Valeur brute (toBase) : value() sur Eloquent renverrait l'enum casté, jamais égal à la chaîne.
        return VideoRenderJob::query()->whereKey($render->getKey())->toBase()->value('status') === RenderStatus::Cancelled->value;
    }

    /**
     * Publie le MP4 validé : analyse (comme un envoi), stockage privé, média de la médiathèque, puis
     * rendu « succeeded ». Rien n'est publié si le rendu n'est plus « rendering » (annulé entre-temps).
     *
     * @throws RenderFailure
     */
    public function complete(VideoRenderJob $render, RenderedVideo $video): bool
    {
        $project = VideoProject::query()->findOrFail($render->video_project_id);
        $metadata = ['source' => 'video_studio', 'video_render_id' => $render->getKey()];

        return $this->publisher->publish($render->user_id, $project->title ?: 'Vidéo', $video, $metadata, function (Media $media) use ($render) {
            $locked = VideoRenderJob::query()->whereKey($render->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== RenderStatus::Rendering) {
                return false; // annulé pendant l'encodage : rien n'est publié
            }
            $media->save();
            $locked->forceFill(['status' => RenderStatus::Succeeded, 'progress' => 100, 'output_media_id' => $media->id, 'finished_at' => now(), 'error_code' => null, 'error_message' => null])->save();
            VideoProject::query()->whereKey($locked->video_project_id)
                ->update(['status' => VideoProjectStatus::Ready->value, 'last_render_id' => $locked->getKey()]);

            return true;
        }) !== null;
    }

    public function cancel(VideoRenderJob $render): VideoRenderJob
    {
        return DB::transaction(function () use ($render) {
            $locked = VideoRenderJob::query()->whereKey($render->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [RenderStatus::Queued, RenderStatus::Rendering], true)) {
                throw VideoConflict::notCancellable();
            }
            $locked->forceFill(['status' => RenderStatus::Cancelled, 'finished_at' => now()])->save();
            $this->releaseProject($locked);

            return $locked;
        });
    }

    /** Fin d'un rendu sans fichier (échec, annulation) : le projet redevient modifiable en brouillon. */
    public function fail(VideoRenderJob $render, string $code, string $message): void
    {
        DB::transaction(function () use ($render, $code, $message) {
            $locked = VideoRenderJob::query()->whereKey($render->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [RenderStatus::Queued, RenderStatus::Rendering], true)) {
                return; // déjà annulé ou terminé
            }
            $locked->forceFill(['status' => RenderStatus::Failed, 'error_code' => $code, 'error_message' => $message, 'finished_at' => now()])->save();
            $this->releaseProject($locked);
        });
    }

    /**
     * Empreinte SHA-256 d'une spécification, sur sa forme canonique (clés triées récursivement) :
     * identique avant et après stockage (MySQL réordonne les clés des colonnes JSON).
     *
     * @param  array<string, mixed>  $spec
     */
    public static function hashSpec(array $spec): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', (string) json_encode($canonical($spec), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function releaseProject(VideoRenderJob $render): void
    {
        VideoProject::query()->whereKey($render->video_project_id)
            ->where('status', VideoProjectStatus::Rendering->value)
            ->update(['status' => VideoProjectStatus::Draft->value]);
    }

    /**
     * Timeline figée au moment de la demande : identifiants de médias seulement (jamais de chemin
     * de fichier ni d'URL), dans l'ordre des calques puis du temps.
     *
     * @return array<string, mixed>
     */
    private function spec(VideoProject $project): array
    {
        $project->load(['tracks.items.asset.media', 'scenes']);
        $items = $project->tracks->flatMap->items;
        if ($items->isEmpty()) {
            throw VideoConflict::emptyTimeline();
        }
        if ($items->contains(fn (VideoTimelineItem $item) => $item->asset !== null && ! ($item->asset->media instanceof Media && $item->asset->media->isUsable()))) {
            throw VideoConflict::unavailableMedia();
        }

        return [
            'schema' => 'soxial.render_spec.v1',
            'project' => [
                'id' => $project->getKey(),
                'aspect_ratio' => $project->aspect_ratio->value,
                'width' => $project->width,
                'height' => $project->height,
                'fps' => $project->fps,
                'duration_ms' => $project->duration_ms,
            ],
            'scenes' => $project->scenes->map(fn ($s) => ['id' => $s->getKey(), 'position' => $s->position, 'duration_ms' => $s->duration_ms])->values()->all(),
            'tracks' => $project->tracks->map(fn ($track) => [
                'id' => $track->getKey(),
                'type' => $track->type->value,
                'position' => $track->position,
                'muted' => $track->muted,
                'hidden' => $track->hidden,
                'items' => $track->items->map(fn (VideoTimelineItem $item) => [
                    'id' => $item->getKey(),
                    'type' => $item->type->value,
                    'media_id' => $item->asset?->media_id,
                    'start_ms' => $item->start_ms,
                    'duration_ms' => $item->duration_ms,
                    'source_start_ms' => $item->source_start_ms,
                    'source_duration_ms' => $item->source_duration_ms,
                    'properties' => $item->properties,
                    'transitions' => $item->transitions,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
