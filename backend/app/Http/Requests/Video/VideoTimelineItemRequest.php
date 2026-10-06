<?php

namespace App\Http\Requests\Video;

use App\Domain\Video\Enums\TimelineItemType;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Ajout (POST /video/tracks/{track}/items) ou modification (PATCH /video/timeline-items/{item}) d'un
 * élément de timeline : déplacement (start_ms, video_track_id), redimensionnement (duration_ms,
 * source_*), contenu (properties). Temps en millisecondes entières. Le type est fixé à la création.
 * Syntaxe ici ; cohérence (piste, asset, chevauchement, extrait) : VideoTimelineService.
 */
class VideoTimelineItemRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('item') ?? $this->route('track'));
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';
        $maxMs = (int) config('video.limits.max_duration_ms');

        return [
            'type' => $creating ? ['required', Rule::enum(TimelineItemType::class)] : ['prohibited'],
            'video_track_id' => $creating ? ['prohibited'] : ['sometimes', 'uuid'],
            'start_ms' => [$required, 'integer', 'min:0', "max:{$maxMs}"],
            'duration_ms' => [$required, 'integer', 'min:1', "max:{$maxMs}"],
            'video_project_asset_id' => ['sometimes', 'nullable', 'uuid'],
            'video_scene_id' => ['sometimes', 'nullable', 'uuid'],
            'source_start_ms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'source_duration_ms' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'properties' => ['sometimes', 'nullable', 'array'],
            'transitions' => ['sometimes', 'nullable', 'array:in,out,duration_ms'],
            'transitions.in' => ['sometimes', Rule::in(['none', 'fade'])],
            'transitions.out' => ['sometimes', Rule::in(['none', 'fade'])],
            'transitions.duration_ms' => ['sometimes', 'integer', 'min:0', 'max:2000'],
            'lock_version' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
