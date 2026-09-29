<?php

namespace Tests\Feature\Content;

use App\Models\SocialAccount;
use App\Models\User;
use Database\Seeders\DemoSocialAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** Comptes sociaux de démonstration (développement local) et parcours Content complet avec eux. */
class DemoAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email' => 'demo-owner@exemple.test']);
        config(['demo.seed_email' => 'demo-owner@exemple.test']);
    }

    private function seedDemo(): void
    {
        $this->seed(DemoSocialAccountsSeeder::class);
    }

    private function demoAccount(string $platform): SocialAccount
    {
        return SocialAccount::query()->ownedBy($this->user)->where('external_account_id', "demo-{$platform}")->firstOrFail();
    }

    public function test_seeder_creates_clearly_marked_demo_accounts_without_tokens(): void
    {
        Http::preventStrayRequests(); // aucun appel réseau autorisé
        $this->seedDemo();

        $accounts = SocialAccount::query()->ownedBy($this->user)->with('platform')->get();
        $this->assertCount(7, $accounts);
        foreach ($accounts as $account) {
            $this->assertTrue($account->isDemo());
            $this->assertSame('development', $account->metadata['environment']);
            $this->assertStringStartsWith('demo-', $account->external_account_id);
            $this->assertStringStartsWith('Compte de démonstration (', $account->display_name);
            $this->assertNull($account->username, 'Pas de nom d\'utilisateur pouvant correspondre à un vrai compte.');
        }
        $this->assertDatabaseCount('social_tokens', 0);
        $this->assertSame('expired', $this->demoAccount('tiktok')->status->value);
        $this->assertSame('connected', $this->demoAccount('facebook')->status->value);
    }

    public function test_seeder_is_idempotent_and_creates_no_user(): void
    {
        $this->seedDemo();
        $this->demoAccount('facebook')->delete();
        $this->seedDemo();

        $this->assertSame(7, SocialAccount::query()->ownedBy($this->user)->count());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_seeder_refuses_to_run_outside_local_environments(): void
    {
        // Appel direct : en production, « db:seed » demanderait en plus une confirmation interactive.
        $this->app['env'] = 'production';
        try {
            $this->expectException(RuntimeException::class);
            $this->app->make(DemoSocialAccountsSeeder::class)->run();
        } finally {
            $this->app['env'] = 'testing';
            $this->assertDatabaseCount('social_accounts', 0);
        }
    }

    public function test_seeder_requires_an_existing_registered_user(): void
    {
        config(['demo.seed_email' => null]);
        $this->assertThrows(fn () => $this->seedDemo(), RuntimeException::class);

        config(['demo.seed_email' => 'inconnu@exemple.test']);
        $this->assertThrows(fn () => $this->seedDemo(), RuntimeException::class);

        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_api_exposes_the_demo_flag_and_never_tokens(): void
    {
        $this->seedDemo();

        $response = $this->actingAs($this->user)->fromSpa()->getJson('/api/v1/social/accounts')->assertOk()->assertJsonCount(7, 'data');
        $this->assertTrue(collect($response->json('data'))->every(fn ($a) => $a['is_demo'] === true));
        $this->assertStringNotContainsString('token', strtolower($response->getContent()));
    }

    public function test_full_content_flow_with_demo_accounts(): void
    {
        Http::preventStrayRequests();
        $this->seedDemo();
        $facebook = $this->demoAccount('facebook');
        $linkedin = $this->demoAccount('linkedin');
        $api = fn () => $this->actingAs($this->user)->fromSpa();

        // Programmer sur deux comptes de démonstration.
        $post = $api()->postJson('/api/v1/content/posts', [
            'mode' => 'schedule',
            'title' => 'Test local',
            'body' => 'Publication de test (comptes de démonstration).',
            'scheduled_at' => now()->addDays(2)->toIso8601String(),
            'targets' => [
                ['social_account_id' => $facebook->id, 'format' => 'feed'],
                ['social_account_id' => $linkedin->id, 'format' => 'feed'],
            ],
        ])->assertCreated()->assertJsonPath('data.status', 'scheduled')->assertJsonCount(2, 'data.variants')->json('data');

        // Filtres et calendrier.
        $api()->getJson('/api/v1/content/posts?platform=linkedin')->assertOk()->assertJsonCount(1, 'data');
        $api()->getJson('/api/v1/content/posts?platform=instagram')->assertOk()->assertJsonCount(0, 'data');
        $api()->getJson('/api/v1/content/posts?from='.urlencode(now()->toIso8601String()).'&to='.urlencode(now()->addWeek()->toIso8601String()))
            ->assertOk()->assertJsonCount(1, 'data');

        // Annuler, reprogrammer, dupliquer : rien n'est publié.
        $api()->postJson("/api/v1/content/posts/{$post['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $api()->postJson("/api/v1/content/posts/{$post['id']}/reschedule", ['scheduled_at' => now()->addDays(3)->toIso8601String()])
            ->assertOk()->assertJsonPath('data.status', 'scheduled');
        $api()->postJson("/api/v1/content/posts/{$post['id']}/duplicate")->assertCreated()->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseCount('publication_attempts', 0);
        $this->assertDatabaseMissing('post_variants', ['status' => 'published']);
    }

    public function test_expired_demo_account_cannot_be_scheduled_but_can_hold_a_draft(): void
    {
        $this->seedDemo();
        $tiktok = $this->demoAccount('tiktok');
        $api = fn () => $this->actingAs($this->user)->fromSpa();

        $api()->postJson('/api/v1/content/posts', [
            'mode' => 'schedule',
            'body' => 'Vidéo',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'targets' => [['social_account_id' => $tiktok->id, 'format' => 'video', 'options' => ['privacy' => 'public']]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['targets.0.social_account_id']);

        $api()->postJson('/api/v1/content/posts', [
            'mode' => 'draft',
            'body' => 'Vidéo',
            'targets' => [['social_account_id' => $tiktok->id, 'format' => 'video', 'options' => ['privacy' => 'public']]],
        ])->assertCreated()->assertJsonPath('data.status', 'draft');
    }

    public function test_demo_accounts_of_another_user_are_not_reachable(): void
    {
        $this->seedDemo();
        $alice = User::factory()->create();

        $this->actingAs($alice)->fromSpa()->getJson('/api/v1/social/accounts')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($alice)->fromSpa()->postJson('/api/v1/content/posts', [
            'mode' => 'draft',
            'body' => 'Tentative',
            'targets' => [['social_account_id' => $this->demoAccount('facebook')->id, 'format' => 'feed']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['targets.0.social_account_id']);
    }
}
