<?php

namespace App\Http\Requests\Content;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Action sans données métier sur une publication (annulation) : seule la version attendue est reçue. */
class PostVersionRequest extends FormRequest
{
    /** Autorisation AVANT validation : un post d'un autre compte répond 404, jamais 422 (IDOR). */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('post'));
    }

    public function rules(): array
    {
        return [
            'lock_version' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
