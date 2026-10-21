<?php

namespace App\Domain\Video;

use App\Domain\Video\Exceptions\VideoConflict;
use App\Domain\Video\Support\OrderedPositions;
use App\Domain\Video\Support\VideoProjectLock;
use App\Models\Media;
use App\Models\VideoProject;
use App\Models\VideoScene;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Scènes d'un projet (ADR-044) : segments narratifs ordonnés (position contiguë 0, 1, 2…).
 * Le fond éventuel (media_id) est un média image ou vidéo utilisable du propriétaire du projet.
 * Les éléments de timeline rattachés à une scène supprimée sont détachés, pas supprimés.
 */
final class VideoSceneService
{
    /** @param array<string, mixed> $data champs validés */
    public function create(VideoProject $project, array $data, ?int $expectedVersion = null): VideoScene
    {
        return DB::transaction(function () use ($project, $data, $expectedVersion) {
            $locked = VideoProjectLock::acquire($project, $expectedVersion);
            $max = (int) config('video.limits.max_scenes');
            if (VideoScene::query()->where('video_project_id', $locked->getKey())->count() >= $max) {
                throw VideoConflict::limitReached('scènes', $max);
            }

            $scene = new VideoScene($this->fields($locked, $data));
            $scene->video_project_id = $locked->getKey();
            $scene->position = OrderedPositions::PENDING;
            $scene->save();
            OrderedPositions::place(VideoScene::class, 'video_project_id', $locked->getKey(), $scene->getKey(), $data['position'] ?? null);
            VideoProjectLock::bump($locked);

            return $scene->refresh();
        });
    }

    /** @param array<string, mixed> $data champs validés */
    public function update(VideoScene $scene, array $data, ?int $expectedVersion = null): VideoScene
    {
        return DB::transaction(function () use ($scene, $data, $expectedVersion) {
            $locked = VideoProjectLock::acquire($scene->project, $expectedVersion);
            $scene->fill($this->fields($locked, $data, $scene))->save();
            if (array_key_exists('position', $data)) {
                OrderedPositions::place(VideoScene::class, 'video_project_id', $locked->getKey(), $scene->getKey(), $data['position']);
            }
            VideoProjectLock::bump($locked);

            return $scene->refresh();
        });
    }

    public function delete(VideoScene $scene, ?int $expectedVersion = null): void
    {
        DB::transaction(function () use ($scene, $expectedVersion) {
            $locked = VideoProjectLock::acquire($scene->project, $expectedVersion);
            $scene->delete(); // éléments de timeline : video_scene_id → NULL (clé étrangère)
            OrderedPositions::compact(VideoScene::class, 'video_project_id', $locked->getKey());
            VideoProjectLock::bump($locked);
        });
    }

    /**
     * Champs modifiables ; media_id (fond) vérifié : média utilisable du propriétaire, image ou vidéo.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(VideoProject $project, array $data, ?VideoScene $scene = null): array
    {
        $fields = array_intersect_key($data, array_flip(['name', 'role', 'duration_ms', 'media_start_ms', 'fit', 'on_screen_text', 'voiceover_text', 'visual_prompt', 'transition', 'settings']));

        if (array_key_exists('media_id', $data)) {
            if ($data['media_id'] === null) {
                $fields += ['media_id' => null, 'media_type' => null, 'media_start_ms' => null];
            } else {
                $media = Media::query()->ownedBy($project->user)->usable()->whereKey($data['media_id'])->first();
                if ($media === null || ! in_array($media->type->value, ['image', 'video'], true)) {
                    throw ValidationException::withMessages(['media_id' => 'Fond introuvable, pas encore prêt ou qui n\'est ni une image ni une vidéo.']);
                }
                $fields['media_id'] = $media->getKey();
                $fields['media_type'] = $media->type->value;
            }
        }
        $mediaType = $fields['media_type'] ?? $scene?->media_type?->value;
        if (($fields['media_start_ms'] ?? null) !== null && $mediaType !== 'video') {
            throw ValidationException::withMessages(['media_start_ms' => 'Le début d\'extrait ne s\'applique qu\'à un fond vidéo.']);
        }

        return $fields;
    }
}
