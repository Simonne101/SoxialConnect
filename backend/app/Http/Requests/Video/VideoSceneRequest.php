<?php

namespace App\Http\Requests\Video;

use App\Domain\Video\Enums\SceneFit;
use App\Domain\Video\Enums\SceneRole;
use App\Domain\Video\Enums\SceneTransition;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Création (POST /video/projects/{project}/scenes) ou modification (PATCH /video/scenes/{scene}) d'une scène. */
class VideoSceneRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('scene') ?? $this->route('project'));
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'role' => ['sometimes', Rule::enum(SceneRole::class)],
            'duration_ms' => [$required, 'integer', 'min:'.config('video.limits.scene_min_duration_ms'), 'max:'.config('video.limits.scene_max_duration_ms')],
            'position' => ['sometimes', 'integer', 'min:0', 'max:'.config('video.limits.max_scenes')],
            'media_id' => ['sometimes', 'nullable', 'uuid'],
            'media_start_ms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'fit' => ['sometimes', Rule::enum(SceneFit::class)],
            'on_screen_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'voiceover_text' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'visual_prompt' => ['sometimes', 'nullable', 'string', 'max:500'],
            'transition' => ['sometimes', Rule::enum(SceneTransition::class)],
            'settings' => ['sometimes', 'nullable', 'array', 'max:20'],
            'lock_version' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
