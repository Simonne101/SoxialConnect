<?php

namespace App\Http\Requests\Users;

use App\Support\Validation\Timezones;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Profil de l'utilisateur courant. L'e-mail et le mot de passe ont leurs propres parcours (Fortify). */
class UpdateMeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'headline' => ['sometimes', 'nullable', 'string', 'max:60'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:280'],
            'timezone' => ['sometimes', 'required', 'string', Rule::in(Timezones::identifiers())],
            'locale' => ['sometimes', 'required', Rule::in(['fr'])],
        ];
    }
}
