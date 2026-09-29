<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Content\Actions\SavePost;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\IndexPostsRequest;
use App\Http\Requests\Content\SavePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/content/posts — CRUD des publications (sans publication réelle en phase 2).
 * Ordre : Authentification (middleware) → Autorisation (Policy) → Validation (FormRequest) → Domaine (SavePost).
 */
class PostController extends Controller
{
    public function index(IndexPostsRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $posts = Post::query()
            ->ownedBy($request->user())
            ->with(['variants.socialAccount.platform', 'media'])
            ->when($filters['status'] ?? null, fn ($q, $statuses) => $q->whereIn('status', $statuses))
            ->when($filters['platform'] ?? null, fn ($q, $platform) => $q->whereHas(
                'variants.socialAccount.platform', fn ($p) => $p->where('key', $platform)
            ))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($w) => $w
                ->where('title', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('body', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('scheduled_at', '>=', Carbon::parse($from)->utc()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('scheduled_at', '<=', Carbon::parse($to)->utc()))
            ->orderByRaw('COALESCE(scheduled_at, updated_at) DESC')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated($posts, PostResource::class);
    }

    public function store(SavePostRequest $request, SavePost $action): JsonResponse
    {
        Gate::authorize('create', Post::class);
        $post = $action->create($request->user(), $request->validated());

        return ApiResponse::created(new PostResource($post), $post->status->value === 'scheduled' ? 'Publication programmée.' : 'Brouillon enregistré.');
    }

    public function show(Post $post): JsonResponse
    {
        Gate::authorize('view', $post);

        return ApiResponse::ok(new PostResource($post->load(['variants.socialAccount.platform', 'media'])));
    }

    public function update(SavePostRequest $request, Post $post, SavePost $action): JsonResponse
    {
        Gate::authorize('update', $post);
        $post = $action->update($request->user(), $post, $request->validated());

        return ApiResponse::ok(new PostResource($post), 'Publication mise à jour.');
    }

    public function destroy(Post $post, SavePost $action): JsonResponse
    {
        Gate::authorize('delete', $post);
        $action->delete(request()->user(), $post);

        return ApiResponse::noContent();
    }
}
