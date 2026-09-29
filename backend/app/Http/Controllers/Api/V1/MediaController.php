<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Media\Actions\ManageMedia;
use App\Domain\Media\Actions\UploadMedia;
use App\Domain\Media\Security\ScanPolicy;
use App\Domain\Media\Support\MediaTag;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\IndexMediaRequest;
use App\Http\Requests\Media\MediaTagRequest;
use App\Http\Requests\Media\StoreMediaRequest;
use App\Http\Requests\Media\UpdateMediaRequest;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/media — médiathèque. Ordre : Authentification (middleware) → Autorisation (Policy)
 * → Validation (FormRequest) → Domaine (Actions). Toutes les requêtes sont filtrées par propriétaire.
 */
class MediaController extends Controller
{
    public function index(IndexMediaRequest $request, ScanPolicy $scanPolicy): JsonResponse
    {
        $f = $request->validated();
        $sort = IndexMediaRequest::SORTS[$f['sort'] ?? 'created_at'];
        $direction = $f['direction'] ?? 'desc';

        $media = Media::query()
            ->ownedBy($request->user())
            ->when($f['type'] ?? null, fn ($q, $type) => $type === 'gif'
                ? $q->where('mime_type', 'image/gif')
                : $q->where('type', $type))
            ->when($f['folder'] ?? null, fn ($q, $folder) => $folder === 'root'
                ? $q->whereNull('folder_id')
                : $q->where('folder_id', $folder))
            ->when(isset($f['tag']) ? MediaTag::normalize($f['tag']) : null, fn ($q, $tag) => $q->whereJsonContains('tags', $tag))
            ->when($f['search'] ?? null, fn ($q, $search) => $q->where('original_name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when($f['ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->orderBy($sort, $direction)
            ->orderBy('id', $direction) // ordre stable entre les pages
            ->paginate($f['per_page'] ?? (int) config('media.per_page.default', 20));

        return ApiResponse::paginated($media, MediaResource::class, null, [
            'antivirus' => ['available' => $scanPolicy->scannerAvailable(), 'required' => $scanPolicy->isRequired()],
        ]);
    }

    public function store(StoreMediaRequest $request, UploadMedia $upload): JsonResponse
    {
        $media = $upload->handle($request->user(), $request->file('file'), $request->validated('folder_id'), $request->normalizedTags());

        return ApiResponse::ok(new MediaResource($media), 'Média importé.', ['duplicates' => $upload->duplicatesOf($media)], 201);
    }

    public function show(Media $media): JsonResponse
    {
        Gate::authorize('view', $media);

        return ApiResponse::ok(new MediaResource($media));
    }

    public function update(UpdateMediaRequest $request, Media $media, ManageMedia $manage): JsonResponse
    {
        Gate::authorize('update', $media);

        return ApiResponse::ok(new MediaResource($manage->update($media, $request->validated())), 'Média mis à jour.');
    }

    public function destroy(Request $request, Media $media, ManageMedia $manage): JsonResponse
    {
        Gate::authorize('delete', $media);
        $manage->delete($request->user(), $media);

        return ApiResponse::noContent();
    }

    /** Tags utilisés par l'utilisateur (filtres de la médiathèque). */
    public function tags(Request $request): JsonResponse
    {
        $userId = $request->user()->getKey();
        if (DB::getDriverName() === 'mysql') {
            $tags = DB::table('media')
                ->join(DB::raw("JSON_TABLE(media.tags, '$[*]' COLUMNS (tag VARCHAR(64) PATH '$')) AS jt"), DB::raw('1'), '=', DB::raw('1'))
                ->where('media.user_id', $userId)
                ->whereNull('media.deleted_at')
                ->distinct()
                ->orderBy('jt.tag')
                ->pluck('jt.tag');
        } else {
            $tags = Media::query()->where('user_id', $userId)->pluck('tags')->flatten()->filter()->unique()->sort()->values();
        }

        return ApiResponse::ok($tags->values());
    }

    public function addTag(MediaTagRequest $request, Media $media, ManageMedia $manage): JsonResponse
    {
        Gate::authorize('update', $media);

        return ApiResponse::ok(new MediaResource($manage->addTag($media, $request->validated('tag'))), 'Tag ajouté.');
    }

    public function removeTag(Media $media, string $tag, ManageMedia $manage): JsonResponse
    {
        Gate::authorize('update', $media);

        return ApiResponse::ok(new MediaResource($manage->removeTag($media, $tag)), 'Tag retiré.');
    }
}
