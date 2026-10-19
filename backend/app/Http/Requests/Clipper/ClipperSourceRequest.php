<?php

namespace App\Http\Requests\Clipper;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Vidéo source rendable d'un projet (PUT /clipper/projects/{project}/source), ADR-048. */
class ClipperSourceRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('clippingProject'));
    }

    public function rules(): array
    {
        return ['media_id' => ['required', 'uuid']];
    }
}
