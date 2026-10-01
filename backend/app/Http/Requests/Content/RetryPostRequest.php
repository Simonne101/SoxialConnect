<?php

namespace App\Http\Requests\Content;

/** Relance d'une publication en échec : version attendue + confirmation si le dernier résultat est inconnu. */
class RetryPostRequest extends PostVersionRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'confirm_unknown' => ['sometimes', 'boolean'],
        ];
    }
}
