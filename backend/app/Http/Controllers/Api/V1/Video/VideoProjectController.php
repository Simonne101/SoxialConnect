<?php

namespace App\Http\Controllers\Api\V1\Video;

use App\Domain\Video\Enums\VideoProjectKind;
use App\Domain\Video\VideoProjectService;
use App\Domain\Video\VideoRenderService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Video\VideoProjectRequest;
use App\Http\Requests\Video\VideoVersionRequest;
use App\Http\Resources\VideoProjectResource;
use App\Models\VideoProject;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Projets Video Studio de l'utilisateur connecté (ADR-044). */
class VideoProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);
        $projects = VideoProject::query()->ownedBy($request->user())
            ->where('kind', VideoProjectKind::Composition->value)
            ->latest('updated_at')
            ->paginate($perPage);

        return ApiResponse::paginated($projects, VideoProjectResource::class);
    }

    public function store(VideoProjectRequest $request, VideoProjectService $projects, VideoRenderService $renders): JsonResponse
    {
        $project = $projects->create($request->user(), $request->validated());

        return ApiResponse::ok(new VideoProjectResource($projects->loadForEditor($project)), 'Projet vidéo créé.', ['renderer_available' => $renders->rendererAvailable()], 201);
    }

    /** Projet complet pour l'éditeur : scènes, pistes et éléments, médias, derniers rendus. */
    public function show(VideoProject $project, VideoProjectService $projects, VideoRenderService $renders): JsonResponse
    {
        Gate::authorize('view', $project);

        return ApiResponse::ok(new VideoProjectResource($projects->loadForEditor($project)), meta: ['renderer_available' => $renders->rendererAvailable()]);
    }

    public function update(VideoProjectRequest $request, VideoProject $project, VideoProjectService $projects): JsonResponse
    {
        $data = $request->safe()->except('lock_version');
        $project = $projects->update($project, $data, $request->has('lock_version') ? $request->integer('lock_version') : null);

        return ApiResponse::ok(new VideoProjectResource($project), 'Projet mis à jour.');
    }

    public function destroy(VideoVersionRequest $request, VideoProject $project, VideoProjectService $projects): JsonResponse
    {
        $projects->delete($project);

        return ApiResponse::noContent();
    }
}
