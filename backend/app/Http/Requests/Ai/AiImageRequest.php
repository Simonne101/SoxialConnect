<?php

namespace App\Http\Requests\Ai;

use App\Domain\AI\Images\ImageFormat;
use App\Models\AiGeneration;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Génération d'une image par IA (POST /ai/images) : prompt et format (1:1, 4:5, 9:16, 16:9). */
class AiImageRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', AiGeneration::class);
    }

    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:3', 'max:'.(int) config('ai.images.max_prompt_chars', 2000)],
            'aspect_ratio' => ['required', Rule::enum(ImageFormat::class)],
        ];
    }
}
