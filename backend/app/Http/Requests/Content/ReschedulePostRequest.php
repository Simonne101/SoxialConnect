<?php

namespace App\Http\Requests\Content;

use App\Support\Validation\Timezones;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Programmation / reprogrammation d'une publication existante. */
class ReschedulePostRequest extends FormRequest
{
    /** Autorisation AVANT validation : un post d'un autre compte répond 404, jamais 422 (IDOR). */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('post'));
    }

    public function rules(): array
    {
        return [
            'scheduled_at' => ['required', 'date', 'after:now'],
            'timezone' => ['sometimes', 'string', Rule::in(Timezones::identifiers())],
            'lock_version' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'scheduled_at.required' => 'Indiquez la date de programmation.',
            'scheduled_at.after' => 'Choisissez une date et une heure à venir.',
        ];
    }
}
