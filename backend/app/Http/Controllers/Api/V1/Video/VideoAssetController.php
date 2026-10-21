<?php

namespace App\Http\Controllers\Api\V1\Video;

use App\Domain\Video\VideoAssetService;
use App\Http\Controllers\Api\V1\Video\Concerns\RespondsWithProjectVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Video\VideoAssetRequest;
use App\Http\Requests\Video\VideoVersionRequest;
use App\Http\Resources\VideoProjectAssetResource;
use App\Models\VideoProject;
use App\Models\VideoProjectAsset;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Médias de la médiathèque rattachés à un projet vidéo (ADR-044) : aucune copie de fichier. */
class VideoAssetController extends Controller
{
    use RespondsWithProjectVersion;

    public function index(VideoProject $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return ApiResponse::ok(VideoProjectAssetResource::collection($project->assets()->with('media')->latest()->get()));
    }

    public function store(VideoAssetRequest $request, VideoProject $project, VideoAssetService $assets): JsonResponse
    {
        $asset = $assets->attach($project, $request->validated('media_id'), $request->has('lock_version') ? $request->integer('lock_version') : null);

        return ApiResponse::ok(new VideoProjectAssetResource($asset->load('media')), 'Média ajouté au projet.', $this->projectMeta($project->id), 201);
    }

    public function destroy(VideoVersionRequest $request, VideoProjectAsset $asset, VideoAssetService $assets): JsonResponse
    {
        $projectId = $asset->video_project_id;
        $assets->detach($asset, $request->has('lock_version') ? $request->integer('lock_version') : null);

        return ApiResponse::ok(null, 'Média retiré du projet.', $this->projectMeta($projectId));
    }
}
