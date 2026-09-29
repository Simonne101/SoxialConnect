<?php

namespace Tests\Feature\Content;

use App\Domain\Users\Enums\UserStatus;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Actions sur une publication : dupliquer, annuler, (re)programmer, verrou de version, IDOR. */
class PostActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->account = SocialAccount::factory()->for($this->user)->create();
    }

    private function createPost(array $overrides = []): array
    {
        return $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', array_merge([
            'mode' => 'draft',
            'title' => 'Conseils visibilité',
            'body' => 'Voici 5 leviers simples pour booster votre visibilité.',
            'targets' => [['social_account_id' => $this->account->id, 'format' => 'feed', 'options' => ['hashtags' => ['conseils']]]],
        ], $overrides))->assertCreated()->json('data');
    }

    private function scheduled(): array
    {
        return $this->createPost(['mode' => 'schedule', 'scheduled_at' => now()->addDay()->toIso8601String()]);
    }

    private function action(string $id, string $name, array $body = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->user)->fromSpa()->postJson("/api/v1/content/posts/{$id}/{$name}", $body);
    }

    // --- Dupliquer ---------------------------------------------------------------------------

    public function test_duplicate_creates_an_independent_draft(): void
    {
        $source = $this->scheduled();

        $copy = $this->action($source['id'], 'duplicate')->assertCreated()
            ->assertJsonPath('message', 'Publication dupliquée dans vos brouillons.')
            ->assertJsonPath('data.title', 'Conseils visibilité (copie)')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.scheduled_at', null)
            ->assertJsonPath('data.lock_version', 0)
            ->assertJsonPath('data.variants.0.status', 'draft')
            ->assertJsonPath('data.variants.0.social_account_id', $this->account->id)
            ->assertJsonPath('data.variants.0.options.hashtags.0', 'conseils')
            ->json('data');

        $this->assertNotSame($source['id'], $copy['id']);
        $this->assertNotSame($source['variants'][0]['id'], $copy['variants'][0]['id']);
        // La source est intacte.
        $this->assertDatabaseHas('posts', ['id' => $source['id'], 'status' => 'scheduled']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'POST_CREATED', 'subject_id' => $copy['id']]);
    }

    public function test_duplicate_copies_media_and_works_for_published_posts(): void
    {
        $media = Media::factory()->for($this->user)->create();
        $source = $this->createPost(['media_ids' => [$media->id]]);
        $this->action($source['id'], 'duplicate')->assertCreated()->assertJsonPath('data.media_ids.0', $media->id);

        $published = Post::factory()->for($this->user)->published()->create(['title' => null]);
        $this->action($published->id, 'duplicate')->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.title', 'Copie')
            ->assertJsonPath('data.published_at', null);
    }

    public function test_duplicate_skips_targets_of_deleted_accounts(): void
    {
        $source = $this->createPost();
        $this->account->delete(); // soft delete (compte déconnecté)

        $this->action($source['id'], 'duplicate')->assertCreated()->assertJsonCount(0, 'data.variants');
    }

    public function test_long_title_stays_within_limit_when_duplicated(): void
    {
        $source = $this->createPost(['title' => str_repeat('a', 255)]);

        $title = $this->action($source['id'], 'duplicate')->assertCreated()->json('data.title');
        $this->assertSame(255, mb_strlen($title));
        $this->assertStringEndsWith(' (copie)', $title);
    }

    // --- Annuler -----------------------------------------------------------------------------

    public function test_cancel_a_scheduled_post(): void
    {
        $post = $this->scheduled();

        $this->action($post['id'], 'cancel', ['lock_version' => $post['lock_version']])->assertOk()
            ->assertJsonPath('message', 'Programmation annulée.')
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.variants.0.status', 'cancelled')
            ->assertJsonPath('data.scheduled_at', $post['scheduled_at']) // date conservée (historique, calendrier)
            ->assertJsonPath('data.lock_version', $post['lock_version'] + 1);

        $this->assertDatabaseHas('audit_logs', ['action' => 'POST_CANCELLED', 'subject_id' => $post['id']]);
    }

    public function test_only_scheduled_posts_can_be_cancelled(): void
    {
        $draft = $this->createPost();
        $this->action($draft['id'], 'cancel')->assertStatus(409)->assertJsonStructure(['message', 'errors']);

        $published = Post::factory()->for($this->user)->published()->create();
        $this->action($published->id, 'cancel')->assertStatus(409);

        $scheduled = $this->scheduled();
        $this->action($scheduled['id'], 'cancel')->assertOk();
        $this->action($scheduled['id'], 'cancel')->assertStatus(409); // déjà annulée
    }

    // --- (Re)programmer ----------------------------------------------------------------------

    public function test_reschedule_a_scheduled_post(): void
    {
        $post = $this->scheduled();
        $at = now()->addDays(3)->startOfMinute();

        $this->action($post['id'], 'reschedule', ['scheduled_at' => $at->toIso8601String(), 'lock_version' => $post['lock_version']])
            ->assertOk()
            ->assertJsonPath('message', 'Publication programmée.')
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.scheduled_at', $at->utc()->toIso8601String())
            ->assertJsonPath('data.variants.0.scheduled_at', $at->utc()->toIso8601String())
            ->assertJsonPath('data.lock_version', $post['lock_version'] + 1);

        $this->assertDatabaseHas('audit_logs', ['action' => 'POST_SCHEDULED', 'subject_id' => $post['id']]);
    }

    public function test_schedule_a_draft_and_reschedule_a_cancelled_post(): void
    {
        $draft = $this->createPost();
        $this->action($draft['id'], 'reschedule', ['scheduled_at' => now()->addDay()->toIso8601String(), 'timezone' => 'America/Toronto'])
            ->assertOk()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.timezone', 'America/Toronto')
            ->assertJsonPath('data.variants.0.status', 'scheduled');

        $cancelled = $this->scheduled();
        $this->action($cancelled['id'], 'cancel')->assertOk();
        $this->action($cancelled['id'], 'reschedule', ['scheduled_at' => now()->addWeek()->toIso8601String()])
            ->assertOk()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.variants.0.status', 'scheduled');
    }

    public function test_reschedule_validation(): void
    {
        $post = $this->scheduled();

        $this->action($post['id'], 'reschedule', [])->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at']);
        $this->action($post['id'], 'reschedule', ['scheduled_at' => now()->subMinute()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at']);
        $this->action($post['id'], 'reschedule', ['scheduled_at' => 'demain'])->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at']);
        $this->action($post['id'], 'reschedule', ['scheduled_at' => now()->addDay()->toIso8601String(), 'timezone' => 'Mars/Olympus'])
            ->assertUnprocessable()->assertJsonValidationErrors(['timezone']);
        // Alias IANA renvoyés par certains navigateurs : acceptés.
        $this->action($post['id'], 'reschedule', ['scheduled_at' => now()->addDay()->toIso8601String(), 'timezone' => 'America/Montreal'])
            ->assertOk()->assertJsonPath('data.timezone', 'America/Montreal');
        $this->action($post['id'], 'reschedule', ['scheduled_at' => now()->addDay()->toIso8601String(), 'lock_version' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors(['lock_version']);
    }

    public function test_reschedule_requires_a_ready_post(): void
    {
        // Brouillon sans réseau.
        $noTarget = $this->createPost(['targets' => []]);
        $this->action($noTarget['id'], 'reschedule', ['scheduled_at' => now()->addDay()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors(['targets']);

        // Brouillon vide.
        $empty = $this->createPost(['body' => '']);
        $this->action($empty['id'], 'reschedule', ['scheduled_at' => now()->addDay()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors(['body']);

        // Compte à reconnecter.
        $expired = SocialAccount::factory()->for($this->user)->expired()->create();
        $stale = $this->createPost(['targets' => [['social_account_id' => $expired->id, 'format' => 'feed']]]);
        $this->action($stale['id'], 'reschedule', ['scheduled_at' => now()->addDay()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors(['targets']);

        $this->assertDatabaseHas('posts', ['id' => $stale['id'], 'status' => 'draft']);
    }

    public function test_published_posts_cannot_be_rescheduled(): void
    {
        $published = Post::factory()->for($this->user)->published()->create();

        $this->action($published->id, 'reschedule', ['scheduled_at' => now()->addDay()->toIso8601String()])->assertStatus(409);
    }

    // --- Verrou de version -------------------------------------------------------------------

    public function test_stale_version_is_rejected_on_every_write(): void
    {
        $post = $this->scheduled();
        $this->action($post['id'], 'reschedule', ['scheduled_at' => now()->addDays(2)->toIso8601String(), 'lock_version' => 0])->assertOk();

        // La version 0 est désormais périmée : chaque écriture est refusée sans rien modifier.
        $this->action($post['id'], 'cancel', ['lock_version' => 0])->assertStatus(409)
            ->assertJsonPath('message', 'Cette publication a été modifiée entre-temps. Rechargez-la avant d\'enregistrer.');
        $this->action($post['id'], 'reschedule', ['scheduled_at' => now()->addDays(4)->toIso8601String(), 'lock_version' => 0])->assertStatus(409);
        $this->actingAs($this->user)->fromSpa()->putJson("/api/v1/content/posts/{$post['id']}", [
            'mode' => 'draft', 'body' => 'Écrasement', 'lock_version' => 0,
        ])->assertStatus(409);

        $this->assertDatabaseHas('posts', ['id' => $post['id'], 'status' => 'scheduled', 'lock_version' => 1]);
        $this->assertDatabaseMissing('posts', ['id' => $post['id'], 'body' => 'Écrasement']);
    }

    // --- IDOR, authentification, compte suspendu ---------------------------------------------

    public function test_actions_on_another_users_post_return_404(): void
    {
        $bob = User::factory()->create();
        $bobPost = Post::factory()->for($bob)->scheduled()->create();

        $this->action($bobPost->id, 'duplicate')->assertNotFound();
        $this->action($bobPost->id, 'cancel')->assertNotFound();
        $this->action($bobPost->id, 'reschedule', ['scheduled_at' => now()->addDay()->toIso8601String()])->assertNotFound();

        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseHas('posts', ['id' => $bobPost->id, 'status' => 'scheduled']);
    }

    public function test_invalid_payload_on_another_users_post_is_404_not_422(): void
    {
        // L'autorisation passe avant la validation : un 422 révélerait que le post existe.
        $bobPost = Post::factory()->for(User::factory())->scheduled()->create();

        $this->action($bobPost->id, 'reschedule', ['scheduled_at' => 'n\'importe quoi'])->assertNotFound();
        $this->action($bobPost->id, 'cancel', ['lock_version' => 'x'])->assertNotFound();
        $this->actingAs($this->user)->fromSpa()->putJson("/api/v1/content/posts/{$bobPost->id}", ['mode' => 'invalide'])->assertNotFound();
    }

    public function test_actions_require_authentication_and_existing_post(): void
    {
        $post = Post::factory()->for($this->user)->scheduled()->create();

        foreach (['duplicate', 'cancel', 'reschedule'] as $name) {
            $this->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/{$name}")->assertUnauthorized();
        }
        foreach (['duplicate', 'cancel', 'reschedule'] as $name) {
            $this->action('01999999-0000-7000-8000-000000000000', $name)->assertNotFound();
        }
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'status' => 'scheduled', 'lock_version' => 0]);
    }

    public function test_suspended_users_cannot_write(): void
    {
        $post = $this->scheduled();
        $this->user->forceFill(['status' => UserStatus::Suspended])->save();

        $this->action($post['id'], 'duplicate')->assertForbidden();
        $this->action($post['id'], 'cancel')->assertForbidden();
        $this->action($post['id'], 'reschedule', ['scheduled_at' => now()->addDay()->toIso8601String()])->assertForbidden();
    }
}
