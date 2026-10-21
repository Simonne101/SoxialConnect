<?php

namespace App\Http\Requests\Video;

use App\Domain\Video\Enums\VideoTrackType;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Création (POST /video/projects/{project}/tracks) ou modification (PATCH /video/tracks/{track}) d'une piste. */
class VideoTrackRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('track') ?? $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'type' => [$this->isMethod('post') ? 'required' : 'sometimes', Rule::enum(VideoTrackType::class)],
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:'.config('video.limits.max_tracks')],
            'locked' => ['sometimes', 'boolean'],
            'muted' => ['sometimes', 'boolean'],
            'hidden' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'nullable', 'array', 'max:20'],
            'lock_version' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
