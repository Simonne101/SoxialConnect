<?php

namespace App\Domain\Video;

use App\Domain\Video\Enums\AspectRatio;
use App\Domain\Video\Enums\RenderStatus;
use App\Domain\Video\Enums\VideoProjectKind;
use App\Domain\Video\Enums\VideoProjectStatus;
use App\Domain\Video\Enums\VideoTrackType;
use App\Domain\Video\Exceptions\VideoConflict;
use App\Domain\Video\Support\VideoProjectLock;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoRenderJob;
use Illuminate\Support\Facades\DB;

/**
 * Projets Video Studio (ADR-044) : création (projet + pistes + scène initiales, en une
 * transaction), réglages, suppression. La résolution découle du format (config/video.php).
 */
final class VideoProjectService
{
    /** Pistes créées avec chaque projet, de l'arrière-plan au premier plan. */
    private const INITIAL_TRACKS = [
        [VideoTrackType::Video, 'Vidéo'],
        [VideoTrackType::Text, 'Texte'],
        [VideoTrackType::Audio, 'Audio'],
    ];

    private const INITIAL_SCENE_MS = 5_000;

    /** @param array{title: string, description?: ?string, aspect_ratio?: ?string, fps?: ?int, target_duration_ms?: ?int, settings?: ?array} $data */
    public function create(User $user, array $data): VideoProject
    {
        return DB::transaction(function () use ($user, $data) {
            $ratio = AspectRatio::from($data['aspect_ratio'] ?? config('video.default_aspect_ratio'));
            $project = new VideoProject([
                'kind' => VideoProjectKind::Composition,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'aspect_ratio' => $ratio,
                'target_duration_ms' => $data['target_duration_ms'] ?? null,
                'settings' => $data['settings'] ?? null,
            ]);
            $project->user_id = $user->getKey();
            $this->applyResolution($project, $ratio, $data['fps'] ?? null);
            $project->forceFill(['status' => VideoProjectStatus::Draft, 'duration_ms' => 0, 'lock_version' => 0])->save();

            foreach (self::INITIAL_TRACKS as $position => [$type, $name]) {
                $project->tracks()->create(['type' => $type, 'name' => $name, 'position' => $position]);
            }
            $project->scenes()->create(['position' => 0, 'name' => 'Scène 1', 'role' => 'body', 'duration_ms' => self::INITIAL_SCENE_MS]);

            return $project;
        });
    }

    /** @param array<string, mixed> $data champs validés (title, description, aspect_ratio, fps, target_duration_ms, settings) */
    public function update(VideoProject $project, array $data, ?int $expectedVersion): VideoProject
    {
        return DB::transaction(function () use ($project, $data, $expectedVersion) {
            $locked = VideoProjectLock::acquire($project, $expectedVersion);
            $locked->fill(array_intersect_key($data, array_flip(['title', 'description', 'target_duration_ms', 'settings'])));
            if (array_key_exists('aspect_ratio', $data) || array_key_exists('fps', $data)) {
                $ratio = isset($data['aspect_ratio']) ? AspectRatio::from($data['aspect_ratio']) : $locked->aspect_ratio;
                $locked->aspect_ratio = $ratio;
                $this->applyResolution($locked, $ratio, $data['fps'] ?? $locked->fps);
            }
            VideoProjectLock::bump($locked);

            return $locked;
        });
    }

    /** Suppression logique ; refusée pendant un rendu (le rendu lit le projet). */
    public function delete(VideoProject $project): void
    {
        DB::transaction(function () use ($project) {
            $locked = VideoProjectLock::acquire($project);
            if ($this->hasActiveRender($locked)) {
                throw VideoConflict::renderActive();
            }
            $locked->delete();
        });
    }

    public function hasActiveRender(VideoProject $project): bool
    {
        return VideoRenderJob::query()
            ->where('video_project_id', $project->getKey())
            ->whereIn('status', [RenderStatus::Queued->value, RenderStatus::Rendering->value])
            ->exists();
    }

    /** Résolution de sortie du format (config/video.php formats) et cadence autorisée. */
    private function applyResolution(VideoProject $project, AspectRatio $ratio, ?int $fps): void
    {
        $size = config("video.formats.{$ratio->value}");
        $project->width = (int) $size['width'];
        $project->height = (int) $size['height'];
        $project->fps = $fps ?? (int) config('video.fps.default');
    }

    /** Charge tout ce qu'affiche l'éditeur, en un nombre fixe de requêtes (pas de N+1). */
    public function loadForEditor(VideoProject $project): VideoProject
    {
        return $project->load([
            'scenes.media',
            'tracks.items',
            'assets.media',
            'renderJobs' => fn ($q) => $q->with('outputMedia')->latest()->limit(10),
        ]);
    }
}
