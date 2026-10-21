<?php

namespace App\Http\Resources;

use App\Models\AiGeneration;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Génération d'image (ADR-046). L'image elle-même est un média normal de la médiathèque
 * (MediaResource, URLs signées), fourni par le contrôleur ; jamais de réponse brute du fournisseur.
 *
 * @mixin AiGeneration
 */
class AiImageGenerationResource extends JsonResource
{
    public function __construct(AiGeneration $resource, private readonly ?Media $media = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $output = $this->output ?? [];
        [$code, $message] = $this->error !== null && str_contains($this->error, ' : ')
            ? explode(' : ', $this->error, 2)
            : [$this->error !== null ? 'IMAGE_FAILED' : null, $this->error];

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'prompt' => $this->input['prompt'] ?? null,
            'aspect_ratio' => $this->input['aspect_ratio'] ?? null,
            'width' => $output['width'] ?? null,
            'height' => $output['height'] ?? null,
            'generated_width' => $output['generated_width'] ?? null,
            'generated_height' => $output['generated_height'] ?? null,
            'error_code' => $code,
            'error_message' => $message,
            'media' => $this->media !== null && $this->media->exists ? new MediaResource($this->media) : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
