<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Content\Actions\CancelPost;
use App\Domain\Content\Actions\DuplicatePost;
use App\Domain\Content\Actions\ReschedulePost;
use App\Domain\Content\Actions\SavePost;
use App\Domain\Content\Enums\PostStatus;
use App\Domain\Publishing\PublishingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\IndexPostsRequest;
use App\Http\Requests\Content\PostVersionRequest;
use App\Http\Requests\Content\ReschedulePostRequest;
use App\Http\Requests\Content\RetryPostRequest;
use App\Http\Requests\Content\SavePostRequest;
use App\Http\Resources\PostResource;
use App\Http\Resources\PublicationAttemptResource;
use App\Models\Post;
use App\Models\PublicationAttempt;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/content/posts — publications. L'envoi passe par le moteur interne (PublishingService) ;
 * sans intégration officielle active, chaque envoi échoue explicitement (NOT_CONFIGURED).
 * Ordre : Authentification (middleware) → Autorisation (Policy) → Validation (FormRequest) → Domaine (Actions).
 */
class PostController extends Controller
{
    public function index(IndexPostsRequest $request): JsonResponse
    {
        $filters = $request->validated();

        // Filtres hors statut : partagés par la liste et par les compteurs d'onglets.
        $scoped = fn (): Builder => Post::query()
            ->ownedBy($request->user())
            ->when($filters['platform'] ?? null, fn ($q, $platform) => $q->whereHas(
                'variants.socialAccount.platform', fn ($p) => $p->where('key', $platform)
            ))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($w) => $w
                ->where('title', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('body', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('scheduled_at', '>=', Carbon::parse($from)->utc()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('scheduled_at', '<=', Carbon::parse($to)->utc()));

        $posts = $scoped()
            ->with(['variants.socialAccount.platform', 'media'])
            ->when($filters['status'] ?? null, fn ($q, $statuses) => $q->whereIn('status', $statuses))
            ->orderByRaw('COALESCE(scheduled_at, updated_at) DESC')
            ->orderByDesc('id') // ordre stable entre les pages
            ->paginate($filters['per_page'] ?? 25);

        $counts = $scoped()->toBase()->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $statusCounts = [];
        foreach (PostStatus::values() as $status) {
            $statusCounts[$status] = (int) ($counts[$status] ?? 0);
        }

        return ApiResponse::paginated($posts, PostResource::class, null, ['status_counts' => $statusCounts]);
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

    public function duplicate(Post $post, DuplicatePost $action): JsonResponse
    {
        Gate::authorize('duplicate', $post);
        $copy = $action->handle(request()->user(), $post);

        return ApiResponse::created(new PostResource($copy), 'Publication dupliquée dans vos brouillons.');
    }

    public function cancel(PostVersionRequest $request, Post $post, CancelPost $action): JsonResponse
    {
        Gate::authorize('update', $post);
        $post = $action->handle($request->user(), $post, $request->has('lock_version') ? $request->integer('lock_version') : null);

        return ApiResponse::ok(new PostResource($post), 'Programmation annulée.');
    }

    public function reschedule(ReschedulePostRequest $request, Post $post, ReschedulePost $action): JsonResponse
    {
        Gate::authorize('update', $post);
        $post = $action->handle(
            $request->user(),
            $post,
            $request->string('scheduled_at')->toString(),
            $request->validated('timezone'),
            $request->has('lock_version') ? $request->integer('lock_version') : null,
        );

        return ApiResponse::ok(new PostResource($post), 'Publication programmée.');
    }

    /** « Publier maintenant » : mise en file immédiate ; le résultat arrive via le job de publication. */
    public function publish(PostVersionRequest $request, Post $post, PublishingService $publishing): JsonResponse
    {
        Gate::authorize('update', $post);
        $post = $publishing->publishNow($request->user(), $post, $request->has('lock_version') ? $request->integer('lock_version') : null);

        return ApiResponse::ok(new PostResource($post), "Publication en file d'attente.");
    }

    /** « Réessayer » : nouvelle tentative pour les variantes en échec (historique conservé). */
    public function retry(RetryPostRequest $request, Post $post, PublishingService $publishing): JsonResponse
    {
        Gate::authorize('update', $post);
        $post = $publishing->retry(
            $request->user(),
            $post,
            $request->has('lock_version') ? $request->integer('lock_version') : null,
            $request->boolean('confirm_unknown'),
        );

        return ApiResponse::ok(new PostResource($post), "Nouvelle tentative en file d'attente.");
    }

    /** Historique des tentatives de publication, de la plus récente à la plus ancienne. */
    public function attempts(Post $post): JsonResponse
    {
        Gate::authorize('view', $post);

        $attempts = PublicationAttempt::query()
            ->whereIn('post_variant_id', $post->variants()->select('id'))
            ->orderByDesc('started_at')
            ->orderByDesc('attempt_number')
            ->limit(200)
            ->get();

        return ApiResponse::ok(PublicationAttemptResource::collection($attempts));
    }
}
