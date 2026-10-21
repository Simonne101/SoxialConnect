<?php

namespace App\Http\Requests\Clipper;

use App\Domain\Video\Enums\AspectRatio;
use App\Models\VideoProject;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Nouveau projet de clipping (POST /clipper/projects) : lien YouTube, et vidéo source de la
 * médiathèque FACULTATIVE (exigée seulement pour le rendu, jointe plus tard si besoin, ADR-048).
 * Le lien est revalidé et la vidéo revérifiée (propriétaire, type) par ClipperService.
 */
class ClipperProjectRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', VideoProject::class);
    }

    public function rules(): array
    {
        return [
            'youtube_url' => ['required', 'string', 'max:2048'],
            'media_id' => ['sometimes', 'nullable', 'uuid'],
            'aspect_ratio' => ['sometimes', Rule::enum(AspectRatio::class)],
            // Attestation de droits sur la vidéo (récupération automatique yt-dlp, ADR-059).
            'rights_confirmed' => ['sometimes', 'boolean'],
        ];
    }
}
