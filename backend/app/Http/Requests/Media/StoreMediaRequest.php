<?php

namespace App\Http\Requests\Media;

use App\Domain\Media\Support\MediaTag;
use App\Models\Media;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Import d'un fichier (multipart). Ici : présence, taille maximale globale, dossier et tags.
 * Le format réel, la taille par type et les dimensions sont contrôlés par MediaFileInspector.
 */
class StoreMediaRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', Media::class);
    }

    public function rules(): array
    {
        $maxKb = max(array_map('intval', (array) config('media.max_size_mb'))) * 1024;

        return [
            'file' => ['required', 'file', "max:{$maxKb}"],
            'folder_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('media_folders', 'id')->where('user_id', $this->user()->getKey())],
            'tags' => ['sometimes', 'array', 'max:'.(int) config('media.tags.max_per_media', 20)],
            'tags.*' => ['string', 'max:60', function ($attribute, $value, $fail) {
                if (! MediaTag::isValid(MediaTag::normalize((string) $value))) {
                    $fail('Tag invalide : lettres, chiffres, espaces, tirets (40 caractères maximum).');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Choisissez un fichier à importer.',
            'file.file' => 'L\'envoi du fichier a échoué (fichier trop volumineux ou connexion interrompue).',
            'file.uploaded' => 'L\'envoi du fichier a échoué (fichier trop volumineux ou connexion interrompue).',
            'file.max' => 'Fichier trop volumineux.',
            'folder_id.exists' => 'Ce dossier est introuvable.',
        ];
    }

    /** @return list<string> */
    public function normalizedTags(): array
    {
        return array_values(array_unique(array_map(fn ($t) => MediaTag::normalize((string) $t), (array) $this->validated('tags', []))));
    }
}
