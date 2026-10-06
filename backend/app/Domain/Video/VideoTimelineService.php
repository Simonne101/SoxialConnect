<?php

namespace App\Domain\Video;

use App\Domain\Video\Enums\TimelineItemType;
use App\Domain\Video\Enums\VideoTrackType;
use App\Domain\Video\Exceptions\VideoConflict;
use App\Domain\Video\Support\OrderedPositions;
use App\Domain\Video\Support\VideoProjectLock;
use App\Models\VideoProject;
use App\Models\VideoProjectAsset;
use App\Models\VideoScene;
use App\Models\VideoTimelineItem;
use App\Models\VideoTrack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Timeline d'un projet (ADR-044) : pistes (calques ordonnés) et éléments (temps en millisecondes).
 * Toutes les écritures passent par le verrou du projet (VideoProjectLock) : les contrôles de
 * cohérence temporelle se font sans course entre deux requêtes. Règles :
 * - type d'élément accepté par le type de piste (VideoTrackType::accepts) ;
 * - asset du MÊME projet, de même type que l'élément, média encore utilisable ; texte sans asset ;
 * - durée >= item_min_duration_ms, fin <= max_duration_ms ;
 * - aucun chevauchement sur une même piste ;
 * - extrait de source (vidéo, audio) dans la durée connue du média ;
 * - piste verrouillée : ni ses éléments ni ses réglages ne changent (sauf le déverrouillage).
 * Après chaque écriture : duration_ms du projet = fin du dernier élément, version incrémentée.
 */
final class VideoTimelineService
{
    // --- Pistes ---------------------------------------------------------------------------------

    /** @param array{type: string, name?: ?string, position?: ?int, muted?: bool, hidden?: bool, metadata?: ?array} $data */
    public function createTrack(VideoProject $project, array $data, ?int $expectedVersion = null): VideoTrack
    {
        return DB::transaction(function () use ($project, $data, $expectedVersion) {
            $locked = VideoProjectLock::acquire($project, $expectedVersion);
            $max = (int) config('video.limits.max_tracks');
            if (VideoTrack::query()->where('video_project_id', $locked->getKey())->count() >= $max) {
                throw VideoConflict::limitReached('pistes', $max);
            }

            $type = VideoTrackType::from($data['type']);
            $track = new VideoTrack([
                'type' => $type,
                'name' => ($data['name'] ?? null) ?: ucfirst($type->value),
                'muted' => $data['muted'] ?? false,
                'hidden' => $data['hidden'] ?? false,
                'metadata' => $data['metadata'] ?? null,
            ]);
            $track->video_project_id = $locked->getKey();
            $track->position = OrderedPositions::PENDING;
            $track->save();
            OrderedPositions::place(VideoTrack::class, 'video_project_id', $locked->getKey(), $track->getKey(), $data['position'] ?? null);
            VideoProjectLock::bump($locked);

            return $track->refresh();
        });
    }

    /** @param array<string, mixed> $data champs validés (name, type, position, locked, muted, hidden, metadata) */
    public function updateTrack(VideoTrack $track, array $data, ?int $expectedVersion = null): VideoTrack
    {
        return DB::transaction(function () use ($track, $data, $expectedVersion) {
            $locked = VideoProjectLock::acquire($track->project, $expectedVersion);
            $track->refresh();
            // Piste verrouillée : seul le déverrouillage est accepté.
            if ($track->locked && ! (array_keys($data) === ['locked'] && $data['locked'] === false)) {
                throw VideoConflict::trackLocked();
            }
            if (isset($data['type']) && $data['type'] !== $track->type->value) {
                if ($track->items()->exists()) {
                    throw VideoConflict::trackNotEmpty();
                }
                $track->type = VideoTrackType::from($data['type']);
            }
            $track->fill(array_intersect_key($data, array_flip(['name', 'locked', 'muted', 'hidden', 'metadata'])))->save();
            if (array_key_exists('position', $data)) {
                OrderedPositions::place(VideoTrack::class, 'video_project_id', $locked->getKey(), $track->getKey(), $data['position']);
            }
            VideoProjectLock::bump($locked);

            return $track->refresh();
        });
    }

    /** Supprime la piste et ses éléments (clé étrangère en cascade). */
    public function deleteTrack(VideoTrack $track, ?int $expectedVersion = null): void
    {
        DB::transaction(function () use ($track, $expectedVersion) {
            $locked = VideoProjectLock::acquire($track->project, $expectedVersion);
            if ($track->refresh()->locked) {
                throw VideoConflict::trackLocked();
            }
            $track->delete();
            OrderedPositions::compact(VideoTrack::class, 'video_project_id', $locked->getKey());
            $this->refreshDuration($locked);
            VideoProjectLock::bump($locked);
        });
    }

    // --- Éléments -------------------------------------------------------------------------------

    /** @param array<string, mixed> $data champs validés (type, start_ms, duration_ms, video_project_asset_id, video_scene_id, source_*, properties, transitions) */
    public function addItem(VideoTrack $track, array $data, ?int $expectedVersion = null): VideoTimelineItem
    {
        return DB::transaction(function () use ($track, $data, $expectedVersion) {
            $locked = VideoProjectLock::acquire($track->project, $expectedVersion);
            $track = $this->writableTrack($track, $locked);
            $max = (int) config('video.limits.max_items_per_track');
            if (VideoTimelineItem::query()->where('video_track_id', $track->getKey())->count() >= $max) {
                throw VideoConflict::limitReached('éléments par piste', $max);
            }

            $item = new VideoTimelineItem(['type' => TimelineItemType::from($data['type'])]);
            $item->video_track_id = $track->getKey();
            $this->apply($item, $data, $locked, $track);
            $item->save();
            $this->refreshDuration($locked);
            VideoProjectLock::bump($locked);

            return $item->refresh();
        });
    }

    /**
     * Modifie, déplace (start_ms, video_track_id) ou redimensionne (duration_ms, source_*) un élément.
     *
     * @param  array<string, mixed>  $data  champs validés
     */
    public function updateItem(VideoTimelineItem $item, array $data, ?int $expectedVersion = null): VideoTimelineItem
    {
        return DB::transaction(function () use ($item, $data, $expectedVersion) {
            $locked = VideoProjectLock::acquire($item->track->project, $expectedVersion);
            $this->writableTrack($item->track, $locked);
            $target = $item->track;
            if (isset($data['video_track_id']) && $data['video_track_id'] !== $item->video_track_id) {
                $target = VideoTrack::query()->where('video_project_id', $locked->getKey())->whereKey($data['video_track_id'])->first();
                if ($target === null) {
                    throw ValidationException::withMessages(['video_track_id' => 'Piste introuvable dans ce projet.']);
                }
                $target = $this->writableTrack($target, $locked);
                $item->video_track_id = $target->getKey();
            }

            $this->apply($item, $data, $locked, $target);
            $item->save();
            $this->refreshDuration($locked);
            VideoProjectLock::bump($locked);

            return $item->refresh();
        });
    }

    public function deleteItem(VideoTimelineItem $item, ?int $expectedVersion = null): void
    {
        DB::transaction(function () use ($item, $expectedVersion) {
            $locked = VideoProjectLock::acquire($item->track->project, $expectedVersion);
            $this->writableTrack($item->track, $locked);
            $item->delete();
            $this->refreshDuration($locked);
            VideoProjectLock::bump($locked);
        });
    }

    // --- Règles ---------------------------------------------------------------------------------

    private function writableTrack(VideoTrack $track, VideoProject $project): VideoTrack
    {
        $track = VideoTrack::query()->whereKey($track->getKey())->where('video_project_id', $project->getKey())->firstOrFail();
        if ($track->locked) {
            throw VideoConflict::trackLocked();
        }

        return $track;
    }

    /** @param array<string, mixed> $data */
    private function apply(VideoTimelineItem $item, array $data, VideoProject $project, VideoTrack $track): void
    {
        $type = $item->type;
        if (! in_array($type, $track->type->accepts(), true)) {
            throw ValidationException::withMessages(['type' => "Un élément « {$type->value} » ne peut pas être posé sur une piste « {$track->type->value} »."]);
        }

        $item->fill(array_intersect_key($data, array_flip(['start_ms', 'duration_ms', 'source_start_ms', 'source_duration_ms', 'transitions'])));
        if (array_key_exists('properties', $data)) {
            $item->properties = $this->properties($type, (array) ($data['properties'] ?? []));
        }

        if (array_key_exists('video_scene_id', $data)) {
            $sceneId = $data['video_scene_id'];
            if ($sceneId !== null && ! VideoScene::query()->where('video_project_id', $project->getKey())->whereKey($sceneId)->exists()) {
                throw ValidationException::withMessages(['video_scene_id' => 'Scène introuvable dans ce projet.']);
            }
            $item->video_scene_id = $sceneId;
        }

        $asset = $this->asset($item, $data, $project);
        $this->checkTiming($item, $asset, $type);
        $this->checkOverlap($item, $track);
    }

    /** Asset de l'élément : du même projet, média utilisable et de même type ; aucun pour un texte. */
    private function asset(VideoTimelineItem $item, array $data, VideoProject $project): ?VideoProjectAsset
    {
        if (! $item->type->needsAsset()) {
            if (! empty($data['video_project_asset_id'])) {
                throw ValidationException::withMessages(['video_project_asset_id' => 'Un élément texte n\'utilise pas de média.']);
            }
            if (blank($item->properties['text'] ?? null)) {
                throw ValidationException::withMessages(['properties.text' => 'Le texte est obligatoire.']);
            }

            return null;
        }

        $assetId = array_key_exists('video_project_asset_id', $data) ? $data['video_project_asset_id'] : $item->video_project_asset_id;
        $asset = $assetId === null ? null : VideoProjectAsset::query()->with('media')
            ->where('video_project_id', $project->getKey())->whereKey($assetId)->first();
        if ($asset === null) {
            throw ValidationException::withMessages(['video_project_asset_id' => 'Média introuvable dans ce projet : ajoutez-le d\'abord aux médias du projet.']);
        }
        if ($asset->media === null || ! $asset->media->isUsable()) {
            throw ValidationException::withMessages(['video_project_asset_id' => 'Ce média n\'est plus disponible dans la médiathèque.']);
        }
        if ($asset->media->type->value !== $item->type->value) {
            throw ValidationException::withMessages(['video_project_asset_id' => "Ce média n'est pas de type « {$item->type->value} »."]);
        }
        $item->video_project_asset_id = $asset->getKey();

        return $asset;
    }

    private function checkTiming(VideoTimelineItem $item, ?VideoProjectAsset $asset, TimelineItemType $type): void
    {
        $min = (int) config('video.limits.item_min_duration_ms');
        $maxEnd = (int) config('video.limits.max_duration_ms');
        if ($item->duration_ms < $min) {
            throw ValidationException::withMessages(['duration_ms' => "Durée minimale : {$min} ms."]);
        }
        if ($item->start_ms + $item->duration_ms > $maxEnd) {
            throw ValidationException::withMessages(['duration_ms' => "L'élément dépasse la durée maximale d'un projet ({$maxEnd} ms)."]);
        }

        if (! $type->hasSource()) {
            if ($item->source_start_ms !== null || $item->source_duration_ms !== null) {
                throw ValidationException::withMessages(['source_start_ms' => 'Un extrait de source ne s\'applique qu\'à la vidéo et à l\'audio.']);
            }

            return;
        }
        // Durée du média connue : l'extrait doit y tenir (sinon vérifiée par FFprobe au rendu, SOURCE_RANGE).
        $mediaDuration = $asset?->media?->duration_ms;
        $end = ($item->source_start_ms ?? 0) + ($item->source_duration_ms ?? $item->duration_ms);
        if ($mediaDuration !== null && $end > $mediaDuration) {
            throw ValidationException::withMessages(['source_start_ms' => "L'extrait dépasse la durée du média ({$mediaDuration} ms)."]);
        }
    }

    private function checkOverlap(VideoTimelineItem $item, VideoTrack $track): void
    {
        $overlaps = VideoTimelineItem::query()
            ->where('video_track_id', $track->getKey())
            ->when($item->exists, fn ($q) => $q->whereKeyNot($item->getKey()))
            ->where('start_ms', '<', $item->start_ms + $item->duration_ms)
            ->whereRaw('`start_ms` + `duration_ms` > ?', [$item->start_ms])
            ->exists();
        if ($overlaps) {
            throw ValidationException::withMessages(['start_ms' => 'Cet élément chevauche un autre élément de la même piste.']);
        }
    }

    /**
     * Propriétés autorisées par type (liste blanche) ; toute autre clé est refusée.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>|null
     */
    private function properties(TimelineItemType $type, array $properties): ?array
    {
        $rules = match ($type) {
            TimelineItemType::Text => [
                'text' => ['required', 'string', 'max:500'],
                'font_size' => ['sometimes', 'integer', 'between:12,200'],
                'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'position' => ['sometimes', Rule::in(['top', 'center', 'bottom'])],
                'align' => ['sometimes', Rule::in(['left', 'center', 'right'])],
            ],
            TimelineItemType::Image => ['fit' => ['sometimes', Rule::in(['cover', 'contain'])]],
            TimelineItemType::Video => ['fit' => ['sometimes', Rule::in(['cover', 'contain'])], 'volume' => ['sometimes', 'integer', 'between:0,100']],
            TimelineItemType::Audio => ['volume' => ['sometimes', 'integer', 'between:0,100']],
        };
        $unknown = array_diff(array_keys($properties), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['properties' => 'Propriétés non prises en charge : '.implode(', ', $unknown).'.']);
        }
        $validator = Validator::make($properties, $rules);
        if ($validator->fails()) {
            $messages = [];
            foreach ($validator->errors()->messages() as $key => $errors) {
                $messages["properties.{$key}"] = $errors;
            }
            throw ValidationException::withMessages($messages);
        }
        $validated = $validator->validated();

        return $validated === [] ? null : $validated;
    }

    /** duration_ms du projet = fin du dernier élément de la timeline (0 si vide). */
    private function refreshDuration(VideoProject $project): void
    {
        $project->duration_ms = (int) VideoTimelineItem::query()
            ->join('video_tracks', 'video_tracks.id', '=', 'video_timeline_items.video_track_id')
            ->where('video_tracks.video_project_id', $project->getKey())
            ->max(DB::raw('`video_timeline_items`.`start_ms` + `video_timeline_items`.`duration_ms`'));
    }
}
