<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtres de la médiathèque. Le tri n'accepte que des clés connues, converties en colonnes côté serveur. */
class IndexMediaRequest extends FormRequest
{
    /** Clé de tri publique → colonne SQL (liste blanche : jamais de colonne fournie par le client). */
    public const SORTS = ['created_at' => 'created_at', 'name' => 'original_name', 'size' => 'size_bytes'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            // « gif » : sous-type d'image (onglet GIF de la médiathèque).
            'type' => ['sometimes', Rule::in(['image', 'video', 'audio', 'document', 'gif'])],
            // « root » : médias hors dossier.
            'folder' => ['sometimes', 'string', function ($attribute, $value, $fail) {
                if ($value !== 'root' && ! preg_match('/^[0-9a-f-]{36}$/i', (string) $value)) {
                    $fail('Dossier invalide.');
                }
            }],
            'tag' => ['sometimes', 'string', 'max:60'],
            'sort' => ['sometimes', Rule::in(array_keys(self::SORTS))],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'ids' => ['sometimes', 'array', 'max:100'],
            'ids.*' => ['uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('media.per_page.max', 100)],
        ];
    }
}
