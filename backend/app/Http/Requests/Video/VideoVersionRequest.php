<?php

namespace App\Http\Requests\Video;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Action sans données métier sur une ressource Video Studio (suppression, rendu, annulation) :
 * seule la version attendue du projet est reçue. Autorisation sur la ressource de la route.
 */
class VideoVersionRequest extends FormRequest
{
    private const ROUTE_MODELS = ['item', 'scene', 'track', 'asset', 'render', 'project'];

    public function authorize(): Response
    {
        foreach (self::ROUTE_MODELS as $parameter) {
            if (($model = $this->route($parameter)) !== null) {
                return Gate::inspect($this->isMethod('delete') ? 'delete' : 'update', $model);
            }
        }

        return Response::denyAsNotFound();
    }

    public function rules(): array
    {
        return ['lock_version' => ['sometimes', 'integer', 'min:0']];
    }
}
