<?php

namespace App\Http\Requests\Clipper;

use App\Models\VideoProject;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Identification d'une vidéo YouTube avant analyse (POST /clipper/lookup), ADR-048. */
class ClipperLookupRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', VideoProject::class);
    }

    public function rules(): array
    {
        return ['youtube_url' => ['required', 'string', 'max:2048']];
    }
}
