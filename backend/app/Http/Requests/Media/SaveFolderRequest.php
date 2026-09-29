<?php

namespace App\Http\Requests\Media;

use App\Domain\Media\Actions\ManageFolders;
use App\Models\MediaFolder;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Création (POST) ou modification (PATCH : renommer, déplacer) d'un dossier. */
class SaveFolderRequest extends FormRequest
{
    /** Autorisation AVANT validation : le dossier d'un autre compte répond 404 (IDOR). */
    public function authorize(): Response
    {
        $folder = $this->route('folder');

        return $folder instanceof MediaFolder ? Gate::inspect('update', $folder) : Gate::inspect('create', MediaFolder::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => ManageFolders::cleanName((string) $this->input('name'))]);
        }
    }

    public function rules(): array
    {
        $creating = ! $this->route('folder') instanceof MediaFolder;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:120'],
            'parent_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('media_folders', 'id')->where('user_id', $this->user()->getKey())],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Donnez un nom au dossier.',
            'parent_id.exists' => 'Ce dossier est introuvable.',
        ];
    }
}
