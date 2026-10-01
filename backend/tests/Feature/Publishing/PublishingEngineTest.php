<?php

namespace Tests\Feature\Publishing;

use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Publishing\Adapters\NotConfiguredPublisher;
use App\Domain\Publishing\Data\PublishResult;
use App\Domain\Publishing\Enums\AttemptTrigger;
use App\Domain\Publishing\PublishingService;
use App\Domain\Publishing\Support\VariantStateMachine;
use App\Jobs\PublishPostVariantJob;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\PublicationAttempt;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;
use RuntimeException;
use Tests\Feature\Publishing\Concerns\FakePublisher;
use Tests\TestCase;

/**
 * Moteur de publication interne : cycle de vie, idempotence, relances, planificateur,
 * adaptateur NOT_CONFIGURED. Aucun appel réseau n'est autorisé (Http::preventStrayRequests).
 */
class PublishingEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        FakePublisher::reset();
        $this->user = User::factory()->create();
        $this->account = SocialAccount::factory()->for($this->user)->create();
    }

    private function service(): PublishingService
    {
        return app(PublishingService::class);
    }

    /** Déclare l'adaptateur de test pour la plateforme du compte (jamais en configuration réelle). */
    private function useFakeAdapter(): void
    {
        config(["publishing.adapters.{$this->account->platform->key}" => FakePublisher::class]);
    }

    private function createPost(array $overrides = []): Post
    {
        $id = $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', array_merge([
            'mode' => 'draft',
            'title' => 'Lancement',
            'body' => 'Texte de la publication.',
            'targets' => [['social_account_id' => $this->account->id, 'format' => 'feed']],
        ], $overrides))->assertCreated()->json('data.id');

        return Post::findOrFail($id);
    }

    private function variant(Post $post): PostVariant
    {
        return $post->variants()->firstOrFail();
    }

    /** Exécute le job en attente (Queue::fake) comme le ferait le worker. */
    private function runPushedJob(int $index = 0): void
    {
        $jobs = Queue::pushed(PublishPostVariantJob::class)->values();
        $job = $jobs[$index];
        $job->handle($this->service());
    }

    // --- Publier maintenant ----------------------------------------------------------------

    public function test_publish_now_queues_every_variant_and_marks_the_post_processing(): void
    {
        Queue::fake();
        $post = $this->createPost();

        $this->service()->publishNow($this->user, $post);

        $variant = $this->variant($post);
        $this->assertSame(VariantStatus::Queued, $variant->status);
        $this->assertNotNull($variant->queued_at);
        $this->assertSame('processing', $post->fresh()->status->value);
        Queue::assertPushedOn('publishing', PublishPostVariantJob::class, fn ($job) => $job->variantId === $variant->id
            && $job->trigger === 'manual' && $job->triggeredBy === $this->user->id && $job->expectedAttempts === 0);
        $this->assertSame(0, PublicationAttempt::count(), 'Aucune tentative avant l\'exécution du job.');
    }

    public function test_not_configured_adapter_fails_explicitly_without_any_network_call(): void
    {
        Queue::fake();
        $post = $this->createPost();
        $this->service()->publishNow($this->user, $post);
        $this->runPushedJob();

        $variant = $this->variant($post)->fresh();
        $this->assertSame(VariantStatus::Failed, $variant->status);
        $this->assertSame('NOT_CONFIGURED', $variant->last_error_code);
        $this->assertSame(NotConfiguredPublisher::MESSAGE, $variant->last_error_message);
        $this->assertNull($variant->published_at);
        $this->assertNull($variant->external_post_id);
        $this->assertSame('failed', $post->fresh()->status->value);
        $this->assertNull($post->fresh()->published_at);

        $attempt = PublicationAttempt::sole();
        $this->assertSame('failed', $attempt->status->value);
        $this->assertSame('NOT_CONFIGURED', $attempt->error_code);
        $this->assertFalse($attempt->retryable);
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertSame('manual', $attempt->trigger->value);
        $this->assertSame($this->account->id, $attempt->social_account_id);
        $this->assertNotNull($attempt->finished_at);

        // Pas de boucle : aucun nouveau job après NOT_CONFIGURED.
        Queue::assertPushed(PublishPostVariantJob::class, 1);
        Http::assertNothingSent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'POST_PUBLISH_FAILED', 'subject_id' => $post->id, 'actor_type' => 'system']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'POST_PUBLISHED']);
    }

    public function test_full_flow_with_the_sync_queue_ends_failed_never_published(): void
    {
        // File « sync » (configuration des tests) : le job s'exécute juste après la validation.
        $post = $this->createPost();
        $this->service()->publishNow($this->user, $post);

        $this->assertSame('failed', $post->fresh()->status->value);
        $this->assertSame(1, PublicationAttempt::count());
        $this->assertSame(0, PostVariant::where('status', 'published')->count());
    }

    public function test_successful_adapter_marks_the_variant_published_with_the_external_id(): void
    {
        $this->useFakeAdapter();
        Queue::fake();
        $post = $this->createPost();
        $this->service()->publishNow($this->user, $post);
        $this->runPushedJob();

        $variant = $this->variant($post)->fresh();
        $this->assertSame(VariantStatus::Published, $variant->status);
        $this->assertSame('ext-1', $variant->external_post_id);
        $this->assertNotNull($variant->published_at);
        $this->assertSame('published', $post->fresh()->status->value);
        $this->assertNotNull($post->fresh()->published_at);
        $this->assertSame('succeeded', PublicationAttempt::sole()->status->value);
        $this->assertSame('ext-1', PublicationAttempt::sole()->external_post_id);

        $request = FakePublisher::$requests[0];
        $this->assertSame('Texte de la publication.', $request->body);
        $this->assertSame(PublicationAttempt::sole()->idempotency_key, $request->idempotencyKey);
        $this->assertDatabaseHas('audit_logs', ['action' => 'POST_PUBLISHED', 'subject_id' => $post->id]);
    }

    // --- Idempotence et concurrence ------------------------------------------------------------

    public function test_a_duplicated_job_runs_only_once(): void
    {
        $this->useFakeAdapter();
        Queue::fake();
        $post = $this->createPost();
        $this->service()->publishNow($this->user, $post);
        $variantId = $this->variant($post)->id;

        $first = $this->service()->execute($variantId, AttemptTrigger::Manual, null, 0);
        $second = $this->service()->execute($variantId, AttemptTrigger::Manual, null, 0);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertCount(1, FakePublisher::$requests);
        $this->assertSame(1, PublicationAttempt::count());
    }

    public function test_a_variant_being_published_cannot_be_taken_by_a_second_worker(): void
    {
        $this->useFakeAdapter();
        Queue::fake();
        $post = $this->createPost();
        $this->service()->publishNow($this->user, $post);
        $variant = $this->variant($post);
        // Un premier worker a réservé la variante (publishing) ; un second reçoit le même job.
        $variant->forceFill(['status' => 'publishing', 'attempts_count' => 1])->save();

        $this->assertNull($this->service()->execute($variant->id, AttemptTrigger::Manual, null, 0));
        $this->assertNull($this->service()->execute($variant->id, AttemptTrigger::Manual, null, 1));
        $this->assertCount(0, FakePublisher::$requests);
        $this->assertSame(0, PublicationAttempt::count());
    }

    public function test_a_published_variant_is_never_executed_again(): void
    {
        $this->useFakeAdapter();
        Queue::fake();
        $post = $this->createPost();
        $this->service()->publishNow($this->user, $post);
        $this->runPushedJob();

        // Rejeu du job d'origine, job forgé avec le compteur courant, relance, republication : rien.
        $this->runPushedJob();
        $this->assertNull($this->service()->execute($this->variant($post)->id, AttemptTrigger::Manual, null, 1));
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/publish")->assertStatus(409);
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/retry")->assertStatus(409);

        $this->assertCount(1, FakePublisher::$requests);
        $this->assertSame(1, PublicationAttempt::count());
    }

    public function test_state_machine_forbids_leaving_published(): void
    {
        foreach (VariantStatus::cases() as $to) {
            $this->assertFalse(VariantStateMachine::allows(VariantStatus::Published, $to));
        }
        $this->assertFalse(VariantStateMachine::allows(VariantStatus::Cancelled, VariantStatus::Publishing));
        $this->assertFalse(VariantStateMachine::allows(VariantStatus::Scheduled, VariantStatus::Published));

        $variant = new PostVariant;
        $variant->status = VariantStatus::Published;
        $this->expectException(LogicException::class);
        VariantStateMachine::transition($variant, VariantStatus::Queued);
    }

    // --- Annulation ------------------------------------------------------------------------

    public function test_a_cancelled_queued_variant_is_never_executed(): void
    {
        $this->useFakeAdapter();
        Queue::fake();
        $post = $this->createPost();
        $this->service()->publishNow($this->user, $post);

        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.variants.0.status', 'cancelled');

        $this->runPushedJob();
        $this->assertSame(VariantStatus::Cancelled, $this->variant($post)->fresh()->status);
        $this->assertCount(0, FakePublisher::$requests);
        $this->assertSame(0, PublicationAttempt::count());
    }

    public function test_cancel_is_refused_while_a_variant_is_publishing(): void
    {
        Queue::fake();
        $post = $this->createPost();
        $this->service()->publishNow($this->user, $post);
        $this->variant($post)->forceFill(['status' => 'publishing'])->save();

        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/cancel")
            ->assertStatus(409)->assertJsonPath('message', 'Une publication est en cours d\'envoi : elle ne peut plus être annulée. Attendez son résultat.');
    }

    public function test_cancel_is_refused_for_failed_and_published_posts(): void
    {
        Queue::fake();
        $post = $this->createPost();
        $this->service()->publishNow($this->user, $post);
        $this->runPushedJob(); // NOT_CONFIGURED → failed

        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/cancel")->assertStatus(409);
    }

    // --- Relances ---------------------------------------------------------------------------

    public function test_retry_creates_a_new_attempt_and_keeps_the_history(): void
    {
        Queue::fake();
        $post = $this->createPost();
        $this->service()->publishNow($this->user, $post);
        $this->runPushedJob();
        $first = PublicationAttempt::sole();

        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/retry")
            ->assertOk()->assertJsonPath('data.status', 'processing')->assertJsonPath('data.variants.0.status', 'queued')
            ->assertJsonPath('data.variants.0.error_message', null);
        Queue::assertPushed(PublishPostVariantJob::class, fn ($job) => $job->trigger === 'retry' && $job->expectedAttempts === 1);
        $this->runPushedJob(1);

        $attempts = PublicationAttempt::orderBy('attempt_number')->get();
        $this->assertCount(2, $attempts);
        $this->assertSame([1, 2], $attempts->pluck('attempt_number')->all());
        $this->assertSame('retry', $attempts[1]->trigger->value);
        $this->assertSame($this->user->id, $attempts[1]->triggered_by);
        $this->assertNotSame($attempts[0]->idempotency_key, $attempts[1]->idempotency_key);
        // La première tentative est intacte.
        $this->assertEquals($first->only(['status', 'error_code', 'finished_at', 'idempotency_key']), $attempts[0]->only(['status', 'error_code', 'finished_at', 'idempotency_key']));
        $this->assertSame(2, $this->variant($post)->fresh()->attempts_count);
        $this->assertSame('failed', $post->fresh()->status->value);
    }

    public function test_temporary_failure_is_retried_automatically_then_stops(): void
    {
        config(['publishing.max_auto_attempts' => 2]);
        $this->useFakeAdapter();
        FakePublisher::reset(
            PublishResult::temporaryFailure('RATE_LIMITED', 'Limite atteinte, nouvel essai plus tard.', 429),
            PublishResult::temporaryFailure('RATE_LIMITED', 'Limite atteinte, nouvel essai plus tard.', 429),
        );
        Queue::fake();
        $post = $this->createPost();
        $this->service()->publishNow($this->user, $post);

        $this->runPushedJob();
        $variant = $this->variant($post)->fresh();
        $this->assertSame(VariantStatus::Queued, $variant->status);
        $this->assertSame('RATE_LIMITED', $variant->last_error_code);
        Queue::assertPushed(PublishPostVariantJob::class, fn ($job) => $job->trigger === 'auto_retry' && $job->expectedAttempts === 1 && $job->cycleAttempt === 2 && $job->delay !== null);

        $this->runPushedJob(1);
        $this->assertSame(VariantStatus::Failed, $this->variant($post)->fresh()->status);
        Queue::assertPushed(PublishPostVariantJob::class, 2); // plus de nouvel essai automatique
        $this->assertSame([true, true], PublicationAttempt::orderBy('attempt_number')->pluck('retryable')->all());
        $this->assertSame(['manual', 'auto_retry'], PublicationAttempt::orderBy('attempt_number')->get()->map(fn ($a) => $a->trigger->value)->all());
    }

    public function test_permanent_failure_does_not_loop(): void
    {
        $this->useFakeAdapter();
        FakePublisher::reset(PublishResult::permanentFailure('CONTENT_REJECTED', 'Contenu refusé par le réseau.', 400));
        Queue::fake();
        $post = $this->createPost();
        $this->service()->publishNow($this->user, $post);
        $this->runPushedJob();

        $this->assertSame(VariantStatus::Failed, $this->variant($post)->fresh()->status);
        $this->assertSame('CONTENT_REJECTED', PublicationAttempt::sole()->error_code);
        $this->assertSame(400, PublicationAttempt::sole()->http_status);
        Queue::assertPushed(PublishPostVariantJob::class, 1);
    }

    public function test_adapter_exception_is_an_unknown_outcome_and_retry_requires_confirmation(): void
    {
        $this->useFakeAdapter();
        FakePublisher::reset(new RuntimeException('connexion interrompue'));
        Queue::fake();
        $post = $this->createPost();
        $this->service()->publishNow($this->user, $post);
        $this->runPushedJob();

        $this->assertSame('unknown', PublicationAttempt::sole()->status->value);
        $this->assertSame(PublishingService::UNKNOWN_CODE, $this->variant($post)->fresh()->last_error_code);
        $this->assertSame('failed', $post->fresh()->status->value);
        Queue::assertPushed(PublishPostVariantJob::class, 1); // jamais de relance aveugle

        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/retry")->assertStatus(409);
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/retry", ['confirm_unknown' => true])
            ->assertOk()->assertJsonPath('data.variants.0.status', 'queued');
    }

    public function test_an_abandoned_job_never_leaves_the_variant_stuck(): void
    {
        Queue::fake();
        $post = $this->createPost();
        $this->service()->publishNow($this->user, $post);
        $job = Queue::pushed(PublishPostVariantJob::class)->first();

        // Délai dépassé avant la réservation : échec explicite, rien n'a été publié.
        $job->failed(new RuntimeException('timeout'));
        $variant = $this->variant($post)->fresh();
        $this->assertSame(VariantStatus::Failed, $variant->status);
        $this->assertSame('PUBLISH_JOB_FAILED', $variant->last_error_code);
        $this->assertSame('failed', $post->fresh()->status->value);
    }

    // --- Pré-contrôles ----------------------------------------------------------------------

    public function test_disconnected_account_fails_with_an_attempt_and_no_adapter_call(): void
    {
        $this->useFakeAdapter();
        Queue::fake();
        $post = $this->createPost();
        $this->service()->publishNow($this->user, $post);
        $this->account->forceFill(['status' => 'expired'])->save();
        $this->runPushedJob();

        $this->assertCount(0, FakePublisher::$requests);
        $this->assertSame('ACCOUNT_DISCONNECTED', PublicationAttempt::sole()->error_code);
        $this->assertSame(VariantStatus::Failed, $this->variant($post)->fresh()->status);
    }

    public function test_unusable_media_fails_before_any_adapter_call(): void
    {
        $this->useFakeAdapter();
        Queue::fake();
        $media = Media::factory()->for($this->user)->create();
        $post = $this->createPost(['media_ids' => [$media->id]]);
        $this->service()->publishNow($this->user, $post);
        $media->delete();
        $this->runPushedJob();

        $this->assertCount(0, FakePublisher::$requests);
        $this->assertSame('MEDIA_UNAVAILABLE', PublicationAttempt::sole()->error_code);
    }

    public function test_a_deleted_post_is_never_sent(): void
    {
        $this->useFakeAdapter();
        Queue::fake();
        $post = $this->createPost();
        $this->service()->publishNow($this->user, $post);
        $post->delete();
        $this->runPushedJob();

        $this->assertCount(0, FakePublisher::$requests);
        $this->assertSame(0, PublicationAttempt::count());
        $this->assertSame(VariantStatus::Cancelled, $this->variant($post)->fresh()->status);
    }

    // --- Planificateur ----------------------------------------------------------------------

    public function test_scheduler_only_queues_due_scheduled_posts(): void
    {
        Queue::fake();
        $due = $this->createPost(['mode' => 'schedule', 'scheduled_at' => now()->addMinutes(5)->toIso8601String()]);
        $future = $this->createPost(['mode' => 'schedule', 'scheduled_at' => now()->addDay()->toIso8601String(), 'targets' => [['social_account_id' => $this->account->id, 'format' => 'story']]]);
        $draft = $this->createPost(['targets' => [['social_account_id' => $this->account->id, 'format' => 'reel']]]);
        $cancelled = $this->createPost(['mode' => 'schedule', 'scheduled_at' => now()->addMinutes(5)->toIso8601String(), 'targets' => [['social_account_id' => $this->account->id, 'format' => 'carousel']]]);
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$cancelled->id}/cancel")->assertOk();
        $trashed = $this->createPost(['mode' => 'schedule', 'scheduled_at' => now()->addMinutes(5)->toIso8601String(), 'targets' => [['social_account_id' => $this->account->id, 'format' => 'video']]]);
        $trashed->delete();

        $this->travel(10)->minutes();
        $this->artisan('publishing:dispatch-due')->assertSuccessful();

        $this->assertSame('processing', $due->fresh()->status->value);
        $this->assertSame(VariantStatus::Queued, $this->variant($due)->fresh()->status);
        $this->assertSame('scheduled', $future->fresh()->status->value);
        $this->assertSame('draft', $draft->fresh()->status->value);
        $this->assertSame('cancelled', $cancelled->fresh()->status->value);
        $this->assertSame(VariantStatus::Scheduled, $this->variant(Post::withTrashed()->find($trashed->id))->status);
        Queue::assertPushed(PublishPostVariantJob::class, 1);
        Queue::assertPushed(PublishPostVariantJob::class, fn ($job) => $job->trigger === 'schedule' && $job->variantId === $this->variant($due)->id);

        // Second passage : rien de plus (idempotent).
        $this->artisan('publishing:dispatch-due')->assertSuccessful();
        Queue::assertPushed(PublishPostVariantJob::class, 1);
    }

    public function test_scheduled_flow_ends_failed_with_an_attempt(): void
    {
        $post = $this->createPost(['mode' => 'schedule', 'scheduled_at' => now()->addMinutes(2)->toIso8601String()]);
        $this->travel(5)->minutes();
        $this->artisan('publishing:dispatch-due')->assertSuccessful(); // file sync : le job s'exécute

        $this->assertSame('failed', $post->fresh()->status->value);
        $this->assertSame('schedule', PublicationAttempt::sole()->trigger->value);
        $this->assertSame('NOT_CONFIGURED', PublicationAttempt::sole()->error_code);
    }

    public function test_recovery_fails_stuck_publishing_and_redispatches_lost_queued_variants(): void
    {
        Queue::fake();
        $stuck = $this->createPost();
        $this->service()->publishNow($this->user, $stuck);
        $lost = $this->createPost(['targets' => [['social_account_id' => $this->account->id, 'format' => 'story']]]);
        $this->service()->publishNow($this->user, $lost);

        // Worker tué après la réservation : tentative « en cours » sans résultat.
        $variant = $this->variant($stuck);
        PublicationAttempt::create([
            'post_variant_id' => $variant->id, 'attempt_number' => 1, 'trigger' => 'manual',
            'idempotency_key' => hash('sha256', $variant->id.'|1'), 'status' => 'in_progress', 'started_at' => now(),
        ]);
        $variant->forceFill(['status' => 'publishing', 'attempts_count' => 1])->save();

        $this->travel(60)->minutes();
        $this->artisan('publishing:dispatch-due')->assertSuccessful();

        $this->assertSame(VariantStatus::Failed, $variant->fresh()->status);
        $this->assertSame('unknown', PublicationAttempt::sole()->status->value);
        Queue::assertPushed(PublishPostVariantJob::class, fn ($job) => $job->trigger === 'recovery' && $job->variantId === $this->variant($lost)->id);
    }
}
