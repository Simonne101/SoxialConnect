<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Media\Actions\ManageFolders;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\SaveFolderRequest;
use App\Http\Resources\MediaFolderResource;
use App\Models\MediaFolder;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** /api/v1/media/folders — dossiers de la médiathèque (liste plate avec parent_id). */
class MediaFolderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $folders = MediaFolder::query()->ownedBy($request->user())->withCount('media')->orderBy('name')->get();

        return ApiResponse::ok(MediaFolderResource::collection($folders)->resolve());
    }

    public function store(SaveFolderRequest $request, ManageFolders $folders): JsonResponse
    {
        $folder = $folders->create($request->user(), $request->validated('name'), $request->validated('parent_id'));

        return ApiResponse::created(new MediaFolderResource($folder->loadCount('media')), 'Dossier créé.');
    }

    public function update(SaveFolderRequest $request, MediaFolder $folder, ManageFolders $folders): JsonResponse
    {
        Gate::authorize('update', $folder);
        $folder = $folders->update($folder, $request->validated());

        return ApiResponse::ok(new MediaFolderResource($folder->loadCount('media')), 'Dossier mis à jour.');
    }

    /** Les sous-dossiers sont supprimés ; les médias reviennent à la racine (jamais supprimés). */
    public function destroy(MediaFolder $folder, ManageFolders $folders): JsonResponse
    {
        Gate::authorize('delete', $folder);
        $folders->delete($folder);

        return ApiResponse::noContent();
    }
}
