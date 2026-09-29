<?php

namespace App\Http\Requests\Media;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Renommer (nom d'affichage) ou déplacer un média. */
class UpdateMediaRequest extends FormRequest
{
    /** Autorisation AVANT validation : le média d'un autre compte répond 404, jamais 422 (IDOR). */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('media'));
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:200'],
            'folder_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('media_folders', 'id')->where('user_id', $this->user()->getKey())],
        ];
    }

    public function messages(): array
    {
        return ['folder_id.exists' => 'Ce dossier est introuvable.'];
    }
}
