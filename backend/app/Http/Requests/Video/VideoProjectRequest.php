<?php

namespace App\Http\Requests\Video;

use App\Domain\Video\Enums\AspectRatio;
use App\Models\VideoProject;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Création (POST) ou réglages (PATCH) d'un projet Video Studio. user_id n'est jamais lu : propriétaire = utilisateur connecté. */
class VideoProjectRequest extends FormRequest
{
    /** Autorisation AVANT validation : un projet d'un autre compte répond 404, jamais 422 (IDOR). */
    public function authorize(): Response
    {
        return $this->isMethod('post')
            ? Gate::inspect('create', VideoProject::class)
            : Gate::inspect('update', $this->route('project'));
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'aspect_ratio' => ['sometimes', Rule::enum(AspectRatio::class)],
            'fps' => ['sometimes', 'integer', Rule::in(config('video.fps.allowed'))],
            'target_duration_ms' => ['sometimes', 'nullable', 'integer', 'min:1000', 'max:'.config('video.limits.max_duration_ms')],
            'settings' => ['sometimes', 'nullable', 'array', 'max:20'],
            'lock_version' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
