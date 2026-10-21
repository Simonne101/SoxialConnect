<?php

namespace App\Http\Controllers\Api\V1\Video;

use App\Domain\Video\VideoRenderService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Video\VideoVersionRequest;
use App\Http\Resources\VideoRenderJobResource;
use App\Models\VideoProject;
use App\Models\VideoRenderJob;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Rendus MP4 d'un projet vidéo (ADR-044, ADR-045). La demande fige la timeline et planifie le
 * rendu (RenderVideoJob, file redis-video) ; le MP4 validé est publié dans la médiathèque.
 */
class VideoRenderController extends Controller
{
    public function index(VideoProject $project, VideoRenderService $renders): JsonResponse
    {
        Gate::authorize('view', $project);

        return ApiResponse::ok(VideoRenderJobResource::collection($project->renderJobs()->with('outputMedia')->latest()->limit(20)->get()), meta: ['renderer_available' => $renders->rendererAvailable()]);
    }

    public function store(VideoVersionRequest $request, VideoProject $project, VideoRenderService $renders): JsonResponse
    {
        $render = $renders->request($project, $request->has('lock_version') ? $request->integer('lock_version') : null);
        $meta = ['renderer_available' => $renders->rendererAvailable()];
        if (! $render->wasRecentlyCreated) {
            // Timeline identique déjà rendue avec succès : la vidéo existante est renvoyée, rien n'est recalculé.
            return ApiResponse::ok(new VideoRenderJobResource($render->load('outputMedia')), 'Cette version de la vidéo existe déjà.', $meta);
        }
        $message = $meta['renderer_available']
            ? 'Rendu planifié.'
            : 'Rendu planifié, mais FFmpeg n\'est pas installé sur le serveur : il ne pourra pas être produit pour le moment.';

        return ApiResponse::ok(new VideoRenderJobResource($render), $message, $meta, 202);
    }

    public function show(VideoRenderJob $render): JsonResponse
    {
        Gate::authorize('view', $render);

        return ApiResponse::ok(new VideoRenderJobResource($render->load('outputMedia')));
    }

    public function cancel(VideoVersionRequest $request, VideoRenderJob $render, VideoRenderService $renders): JsonResponse
    {
        return ApiResponse::ok(new VideoRenderJobResource($renders->cancel($render)), 'Rendu annulé.');
    }
}
