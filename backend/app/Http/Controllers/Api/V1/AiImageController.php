<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\AI\Enums\AiTask;
use App\Domain\AI\Exceptions\AiProviderException;
use App\Domain\AI\Images\AiImageService;
use App\Domain\AI\Images\ImageFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AiImageRequest;
use App\Http\Resources\AiImageGenerationResource;
use App\Models\AiGeneration;
use App\Models\Media;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Génération d'images par IA (ADR-046) : demande (traitée par le worker « ai »), suivi, historique.
 * Les images produites sont des médias normaux de la médiathèque.
 */
class AiImageController extends Controller
{
    public function index(Request $request, AiImageService $images): JsonResponse
    {
        Gate::authorize('viewAny', AiGeneration::class);
        $generations = AiGeneration::query()
            ->where('user_id', $request->user()->getKey())
            ->where('task', AiTask::Image->value)
            ->latest()
            ->limit(20)
            ->get();

        // Médias produits, chargés en une requête (pas de N+1).
        $ids = $generations->map(fn (AiGeneration $g) => $g->output['media_id'] ?? null)->filter()->values();
        $media = Media::query()->where('user_id', $request->user()->getKey())->whereKey($ids)->get()->keyBy('id');

        return ApiResponse::ok(
            $generations->map(fn (AiGeneration $g) => (new AiImageGenerationResource($g, $media->get($g->output['media_id'] ?? '')))->resolve($request))->values(),
            meta: ['configured' => $images->isConfigured()],
        );
    }

    public function store(AiImageRequest $request, AiImageService $images): JsonResponse
    {
        try {
            $generation = $images->request($request->user(), (string) $request->validated('prompt'), $request->enum('aspect_ratio', ImageFormat::class));
        } catch (AiProviderException) {
            return response()->json([
                'message' => 'La génération d\'images n\'est pas configurée sur ce serveur (fournisseur ou modèle d\'image manquant).',
                'errors' => (object) [],
                'code' => 'AI_NOT_CONFIGURED',
            ], 503);
        }

        return ApiResponse::ok(new AiImageGenerationResource($generation), 'Génération de l\'image lancée.', status: 202);
    }

    public function show(AiGeneration $generation, AiImageService $images): JsonResponse
    {
        Gate::authorize('view', $generation);
        abort_unless($generation->task === AiTask::Image, 404);

        return ApiResponse::ok(new AiImageGenerationResource($generation, $images->outputMedia($generation)));
    }
}
