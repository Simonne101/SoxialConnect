<?php

namespace App\Http\Controllers\Api\V1\Video;

use App\Domain\Video\VideoSceneService;
use App\Http\Controllers\Api\V1\Video\Concerns\RespondsWithProjectVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Video\VideoSceneRequest;
use App\Http\Requests\Video\VideoVersionRequest;
use App\Http\Resources\VideoSceneResource;
use App\Models\VideoProject;
use App\Models\VideoScene;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Scènes d'un projet vidéo (ADR-044). */
class VideoSceneController extends Controller
{
    use RespondsWithProjectVersion;

    public function index(VideoProject $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return ApiResponse::ok(VideoSceneResource::collection($project->scenes()->with('media')->get()));
    }

    public function store(VideoSceneRequest $request, VideoProject $project, VideoSceneService $scenes): JsonResponse
    {
        $scene = $scenes->create($project, $request->safe()->except('lock_version'), $this->version($request));

        return ApiResponse::ok(new VideoSceneResource($scene->load('media')), 'Scène ajoutée.', $this->projectMeta($project->id), 201);
    }

    public function update(VideoSceneRequest $request, VideoScene $scene, VideoSceneService $scenes): JsonResponse
    {
        $scene = $scenes->update($scene, $request->safe()->except('lock_version'), $this->version($request));

        return ApiResponse::ok(new VideoSceneResource($scene->load('media')), 'Scène mise à jour.', $this->projectMeta($scene->video_project_id));
    }

    public function destroy(VideoVersionRequest $request, VideoScene $scene, VideoSceneService $scenes): JsonResponse
    {
        $projectId = $scene->video_project_id;
        $scenes->delete($scene, $this->version($request));

        return ApiResponse::ok(null, 'Scène supprimée.', $this->projectMeta($projectId));
    }

    private function version(VideoSceneRequest|VideoVersionRequest $request): ?int
    {
        return $request->has('lock_version') ? $request->integer('lock_version') : null;
    }
}
