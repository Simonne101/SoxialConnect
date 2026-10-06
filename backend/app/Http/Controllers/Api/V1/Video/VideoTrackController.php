<?php

namespace App\Http\Controllers\Api\V1\Video;

use App\Domain\Video\VideoTimelineService;
use App\Http\Controllers\Api\V1\Video\Concerns\RespondsWithProjectVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Video\VideoTrackRequest;
use App\Http\Requests\Video\VideoVersionRequest;
use App\Http\Resources\VideoTrackResource;
use App\Models\VideoProject;
use App\Models\VideoTrack;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Pistes de timeline d'un projet vidéo (ADR-044). */
class VideoTrackController extends Controller
{
    use RespondsWithProjectVersion;

    public function index(VideoProject $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return ApiResponse::ok(VideoTrackResource::collection($project->tracks()->with('items')->get()));
    }

    public function store(VideoTrackRequest $request, VideoProject $project, VideoTimelineService $timeline): JsonResponse
    {
        $track = $timeline->createTrack($project, $request->safe()->except('lock_version'), $this->version($request));

        return ApiResponse::ok(new VideoTrackResource($track->load('items')), 'Piste ajoutée.', $this->projectMeta($project->id), 201);
    }

    public function update(VideoTrackRequest $request, VideoTrack $track, VideoTimelineService $timeline): JsonResponse
    {
        $track = $timeline->updateTrack($track, $request->safe()->except('lock_version'), $this->version($request));

        return ApiResponse::ok(new VideoTrackResource($track->load('items')), 'Piste mise à jour.', $this->projectMeta($track->video_project_id));
    }

    public function destroy(VideoVersionRequest $request, VideoTrack $track, VideoTimelineService $timeline): JsonResponse
    {
        $projectId = $track->video_project_id;
        $timeline->deleteTrack($track, $this->version($request));

        return ApiResponse::ok(null, 'Piste supprimée.', $this->projectMeta($projectId));
    }

    private function version(VideoTrackRequest|VideoVersionRequest $request): ?int
    {
        return $request->has('lock_version') ? $request->integer('lock_version') : null;
    }
}
