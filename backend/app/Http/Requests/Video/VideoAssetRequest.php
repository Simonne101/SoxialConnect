<?php

namespace App\Http\Requests\Video;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Rattachement d'un média EXISTANT de la médiathèque à un projet (POST /video/projects/{project}/assets). */
class VideoAssetRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'media_id' => ['required', 'uuid'],
            'lock_version' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
