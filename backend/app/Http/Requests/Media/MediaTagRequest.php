<?php

namespace App\Http\Requests\Media;

use App\Domain\Media\Support\MediaTag;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Ajout d'un tag à un média. */
class MediaTagRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('media'));
    }

    public function rules(): array
    {
        return [
            'tag' => ['required', 'string', 'max:60', function ($attribute, $value, $fail) {
                if (! MediaTag::isValid(MediaTag::normalize((string) $value))) {
                    $fail('Tag invalide : lettres, chiffres, espaces, tirets (40 caractères maximum).');
                }
            }],
        ];
    }
}
