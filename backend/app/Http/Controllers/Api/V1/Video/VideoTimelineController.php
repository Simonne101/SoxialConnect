<?php

namespace App\Http\Controllers\Api\V1\Video;

use App\Domain\Video\VideoTimelineService;
use App\Http\Controllers\Api\V1\Video\Concerns\RespondsWithProjectVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Video\VideoTimelineItemRequest;
use App\Http\Requests\Video\VideoVersionRequest;
use App\Http\Resources\VideoTimelineItemResource;
use App\Http\Resources\VideoTrackResource;
use App\Models\VideoProject;
use App\Models\VideoTimelineItem;
use App\Models\VideoTrack;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Timeline d'un projet vidéo (ADR-044) : lecture complète, ajout, modification (déplacement,
 * redimensionnement, contenu) et suppression d'éléments. Un seul PATCH couvre déplacer et
 * redimensionner : pas d'endpoint par micro-action.
 */
class VideoTimelineController extends Controller
{
    use RespondsWithProjectVersion;

    public function show(VideoProject $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return ApiResponse::ok([
            'duration_ms' => (int) $project->duration_ms,
            'lock_version' => $project->lock_version,
            'tracks' => VideoTrackResource::collection($project->tracks()->with('items')->get()),
        ]);
    }

    public function store(VideoTimelineItemRequest $request, VideoTrack $track, VideoTimelineService $timeline): JsonResponse
    {
        $item = $timeline->addItem($track, $request->safe()->except('lock_version'), $this->version($request));

        return ApiResponse::ok(new VideoTimelineItemResource($item), 'Élément ajouté.', $this->projectMeta($track->video_project_id), 201);
    }

    public function update(VideoTimelineItemRequest $request, VideoTimelineItem $item, VideoTimelineService $timeline): JsonResponse
    {
        $projectId = $item->track->video_project_id;
        $item = $timeline->updateItem($item, $request->safe()->except('lock_version'), $this->version($request));

        return ApiResponse::ok(new VideoTimelineItemResource($item), 'Élément mis à jour.', $this->projectMeta($projectId));
    }

    public function destroy(VideoVersionRequest $request, VideoTimelineItem $item, VideoTimelineService $timeline): JsonResponse
    {
        $projectId = $item->track->video_project_id;
        $timeline->deleteItem($item, $this->version($request));

        return ApiResponse::ok(null, 'Élément supprimé.', $this->projectMeta($projectId));
    }

    private function version(VideoTimelineItemRequest|VideoVersionRequest $request): ?int
    {
        return $request->has('lock_version') ? $request->integer('lock_version') : null;
    }
}
