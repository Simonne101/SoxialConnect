<?php

namespace Tests\Feature\Publishing;

use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Publishing\Data\PublishResult;
use App\Domain\Publishing\PublishingService;
use App\Jobs\PublishPostVariantJob;
use App\Jobs\ReconcilePublicationJob;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\PublicationAttempt;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Publishing\Concerns\FakeTrackingPublisher;
use Tests\TestCase;

/**
 * Publication asynchrone (PublishOutcome::Processing) : suivi par ReconcilePublicationJob,
 * conclusion, délai maximal, balayage de secours, reprise après incident, sans aucun doublon.
 */
class AsyncPublishingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        FakeTrackingPublisher::reset();
        $this->user = User::factory()->create();
        $this->account = SocialAccount::factory()->for($this->user)->create();
        config(["publishing.adapters.{$this->account->platform->key}" => FakeTrackingPublisher::class]);
    }

    private function service(): PublishingService
    {
        return app(PublishingService::class);
    }

    /** Publie et exécute le job d'envoi : la tentative est « en traitement ». */
    private function publishedToProcessing(): Post
    {
        Queue::fake();
        $id = $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', [
            'mode' => 'draft',
            'body' => 'Vidéo asynchrone',
            'targets' => [['social_account_id' => $this->account->id, 'format' => 'feed']],
        ])->assertCreated()->json('data.id');
        $post = Post::findOrFail($id);
        $this->service()->publishNow($this->user, $post);
        Queue::pushed(PublishPostVariantJob::class)->first()->handle($this->service());

        return $post;
    }

    private function variant(Post $post): PostVariant
    {
        return $post->variants()->firstOrFail();
    }

    /** Exécute le dernier job de suivi poussé. */
    private function runLastReconcile(): ?ReconcilePublicationJob
    {
        $job = Queue::pushed(ReconcilePublicationJob::class)->last();
        $job?->handle($this->service());

        return $job;
    }

    public function test_processing_keeps_the_variant_publishing_and_tracks_the_publish_id(): void
    {
        $post = $this->publishedToProcessing();

        $attempt = PublicationAttempt::sole();
        $this->assertSame('in_progress', $attempt->status->value);
        $this->assertSame('track-1', $attempt->external_post_id);
        $this->assertFalse($attempt->retryable);
        $this->assertNull($attempt->finished_at);
        $this->assertSame(VariantStatus::Publishing, $this->variant($post)->fresh()->status);
        $this->assertSame('processing', $post->fresh()->status->value);
        Queue::assertPushed(PublishPostVariantJob::class, 1); // aucun nouvel envoi
        Queue::assertPushed(ReconcilePublicationJob::class, fn ($job) => $job->trackingId === 'track-1' && $job->check === 1 && $job->attemptId === $attempt->id);
    }

    public function test_reconcile_publishes_on_completion_with_the_real_post_id(): void
    {
        FakeTrackingPublisher::reset(PublishResult::processing('track-1'), PublishResult::success('7300000000000000001', null, ['publish_id' => 'track-1']));
        $post = $this->publishedToProcessing();

        $this->runLastReconcile();
        $this->assertSame(VariantStatus::Publishing, $this->variant($post)->fresh()->status);
        Queue::assertPushed(ReconcilePublicationJob::class, fn ($job) => $job->check === 2);

        $this->runLastReconcile();
        $attempt = PublicationAttempt::sole();
        $this->assertSame('succeeded', $attempt->status->value);
        $this->assertSame('7300000000000000001', $attempt->external_post_id);
        $this->assertSame('track-1', $attempt->response_excerpt['publish_id']);
        $variant = $this->variant($post)->fresh();
        $this->assertSame(VariantStatus::Published, $variant->status);
        $this->assertSame('7300000000000000001', $variant->external_post_id);
        $this->assertSame('published', $post->fresh()->status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'POST_PUBLISHED', 'subject_id' => $post->id]);
        $this->assertSame(1, FakeTrackingPublisher::$publishCalls);
    }

    public function test_completion_without_a_post_id_keeps_the_publish_id(): void
    {
        FakeTrackingPublisher::reset(PublishResult::success('track-1'));
        $post = $this->publishedToProcessing();
        $this->runLastReconcile();

        $this->assertSame('track-1', PublicationAttempt::sole()->external_post_id);
        $this->assertSame(VariantStatus::Published, $this->variant($post)->fresh()->status);
    }

    public function test_reported_failure_fails_without_automatic_retry(): void
    {
        FakeTrackingPublisher::reset(PublishResult::permanentFailure('VIDEO_REJECTED', 'Vidéo refusée par le réseau.'));
        $post = $this->publishedToProcessing();
        $this->runLastReconcile();

        $this->assertSame('failed', PublicationAttempt::sole()->status->value);
        $this->assertSame('VIDEO_REJECTED', $this->variant($post)->fresh()->last_error_code);
        $this->assertSame('failed', $post->fresh()->status->value);
        Queue::assertPushed(PublishPostVariantJob::class, 1);
        Queue::assertPushed(ReconcilePublicationJob::class, 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'POST_PUBLISH_FAILED', 'subject_id' => $post->id]);
    }

    public function test_unusable_status_is_unknown_and_retry_requires_confirmation(): void
    {
        FakeTrackingPublisher::reset(PublishResult::unknown('UNEXPECTED_STATUS', 'Statut inattendu.'));
        $post = $this->publishedToProcessing();
        $this->runLastReconcile();

        $this->assertSame('unknown', PublicationAttempt::sole()->status->value);
        $this->assertSame(PublishingService::UNKNOWN_CODE, $this->variant($post)->fresh()->last_error_code);
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/retry")->assertStatus(409);
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/retry", ['confirm_unknown' => true])
            ->assertOk()->assertJsonPath('data.variants.0.status', 'queued');
        Queue::pushed(PublishPostVariantJob::class)->last()->handle($this->service());
        $this->assertSame(['unknown', 'in_progress'], PublicationAttempt::orderBy('attempt_number')->get()->map(fn ($a) => $a->status->value)->all());
        $this->assertSame(2, FakeTrackingPublisher::$publishCalls);
    }

    public function test_transient_check_errors_keep_tracking_then_deadline_becomes_unknown(): void
    {
        FakeTrackingPublisher::reset(new RuntimeException('réseau'), PublishResult::temporaryFailure('RATE_LIMITED', 'Limite.'));
        $post = $this->publishedToProcessing();

        $this->runLastReconcile(); // exception : suivi maintenu
        $this->runLastReconcile(); // 429 : suivi maintenu
        $this->assertSame('in_progress', PublicationAttempt::sole()->status->value);
        Queue::assertPushed(ReconcilePublicationJob::class, fn ($job) => $job->check === 3);

        $this->travel(121)->minutes();
        $this->runLastReconcile();
        $this->assertSame('unknown', PublicationAttempt::sole()->status->value);
        $this->assertSame('failed', $post->fresh()->status->value);
        Queue::assertPushed(PublishPostVariantJob::class, 1); // jamais de nouvel envoi
    }

    public function test_reconcile_delays_follow_the_configured_schedule(): void
    {
        $this->publishedToProcessing();
        for ($i = 0; $i < 6; $i++) {
            $this->runLastReconcile();
        }
        $delays = Queue::pushed(ReconcilePublicationJob::class)->map(fn ($job) => $job->check)->values()->all();
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $delays);
        $last = Queue::pushed(ReconcilePublicationJob::class)->last();
        $this->assertEqualsWithDelta(now()->addSeconds(120)->getTimestamp(), $last->delay->getTimestamp(), 2);
    }

    public function test_duplicated_or_stale_reconcile_jobs_conclude_only_once(): void
    {
        FakeTrackingPublisher::reset(PublishResult::success('POST1'), PublishResult::success('POST2'));
        $post = $this->publishedToProcessing();
        $job = Queue::pushed(ReconcilePublicationJob::class)->first();

        $job->handle($this->service());
        $job->handle($this->service()); // doublon : tentative déjà conclue
        (new ReconcilePublicationJob($job->attemptId, $job->variantId, 'autre-jeton'))->handle($this->service());
        (new ReconcilePublicationJob($job->attemptId, 'autre-variante', $job->trackingId))->handle($this->service());

        $this->assertSame(1, FakeTrackingPublisher::$statusCalls);
        $this->assertSame('POST1', PublicationAttempt::sole()->external_post_id);
        $this->assertSame(VariantStatus::Published, $this->variant($post)->fresh()->status);
    }

    public function test_recover_stale_leaves_tracked_publications_alone(): void
    {
        $tracked = $this->publishedToProcessing();
        $this->travel(60)->minutes();
        $this->artisan('publishing:dispatch-due')->assertSuccessful();

        $this->assertSame(VariantStatus::Publishing, $this->variant($tracked)->fresh()->status);
        $this->assertSame('in_progress', PublicationAttempt::sole()->status->value);
    }

    public function test_recover_stale_still_abandons_untracked_publishing_variants(): void
    {
        Queue::fake();
        $post = $this->publishedToProcessing();
        // Envoi interrompu AVANT l'identifiant de suivi (worker tué pendant l'upload).
        PublicationAttempt::query()->update(['external_post_id' => null]);
        $this->travel(60)->minutes();
        $this->artisan('publishing:dispatch-due')->assertSuccessful();

        $this->assertSame('unknown', PublicationAttempt::sole()->status->value);
        $this->assertSame(VariantStatus::Failed, $this->variant($post)->fresh()->status);
    }

    public function test_sweep_redispatches_lost_tracking_jobs_idempotently(): void
    {
        $this->publishedToProcessing();
        $before = Queue::pushed(ReconcilePublicationJob::class)->count();

        $this->travel(6)->minutes();
        $this->assertSame(1, $this->service()->sweepProcessing());
        $this->assertSame(0, $this->service()->sweepProcessing()); // déjà relancé
        $this->assertSame($before + 1, Queue::pushed(ReconcilePublicationJob::class)->count());
    }

    public function test_processing_post_cannot_be_cancelled_edited_or_republished(): void
    {
        $post = $this->publishedToProcessing();

        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/cancel")->assertStatus(409);
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/publish")->assertStatus(409);
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/retry")->assertStatus(409);
        $this->actingAs($this->user)->fromSpa()->putJson("/api/v1/content/posts/{$post->id}", ['mode' => 'draft', 'body' => 'x', 'targets' => []])->assertStatus(409);
        $this->assertSame(1, FakeTrackingPublisher::$publishCalls);
    }

    public function test_deleted_post_is_still_concluded_for_history(): void
    {
        FakeTrackingPublisher::reset(PublishResult::success('POSTX'));
        $post = $this->publishedToProcessing();
        $post->delete();
        $this->runLastReconcile();

        $this->assertSame('succeeded', PublicationAttempt::sole()->status->value);
        $this->assertSame(VariantStatus::Published, $this->variant(Post::withTrashed()->find($post->id))->status);
    }
}
