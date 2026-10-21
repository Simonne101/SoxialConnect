<?php

namespace App\Http\Controllers\Api\V1\Clipper;

use App\Domain\Video\Clipper\ClipperService;
use App\Domain\Video\Enums\AspectRatio;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clipper\ClipRequest;
use App\Http\Resources\VideoClipResource;
use App\Models\VideoClip;
use App\Models\VideoProject;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/** Génération des clips ≤ 60 s du YouTube Clipper (ADR-046), rendus par le worker vidéo. */
class ClipController extends Controller
{
    /** Passage choisi par l'utilisateur. */
    public function store(ClipRequest $request, VideoProject $clippingProject, ClipperService $clipper): JsonResponse
    {
        $clip = $clipper->requestClip(
            $clippingProject,
            $request->integer('start_ms'),
            $request->integer('end_ms'),
            $this->aspectRatio($request, $clippingProject->aspect_ratio),
            $request->validated('title'),
            null,
            $request->boolean('subtitles'),
            $request->validated('subtitle_style'),
        );

        return ApiResponse::ok(new VideoClipResource($clip), 'Génération du clip lancée.', status: 202);
    }

    /** Passage proposé par l'analyse (bornes ajustables, toujours ≤ 60 s). */
    public function render(ClipRequest $request, VideoClip $clip, ClipperService $clipper): JsonResponse
    {
        $project = $clip->project;
        $clip = $clipper->requestClip(
            $project,
            $request->has('start_ms') ? $request->integer('start_ms') : $clip->start_ms,
            $request->has('end_ms') ? $request->integer('end_ms') : $clip->end_ms,
            $this->aspectRatio($request, $clip->aspect_ratio),
            $request->validated('title'),
            $clip,
            $request->boolean('subtitles'),
            $request->validated('subtitle_style'),
        );

        return ApiResponse::ok(new VideoClipResource($clip), 'Génération du clip lancée.', status: 202);
    }

    private function aspectRatio(ClipRequest $request, AspectRatio $default): AspectRatio
    {
        return $request->has('aspect_ratio') ? AspectRatio::from((string) $request->validated('aspect_ratio')) : $default;
    }
}
