<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\SocialPlatform;
use App\Models\User;
use Database\Seeders\SocialPlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contrat de GET /api/v1/dashboard/summary (étape 7, P0 « vérité des écrans ») : comptages réels issus de la
 * base, propres à l'utilisateur ; métriques d'audience null (« non disponible », jamais 0) tant qu'aucune
 * synchronisation Analytics n'existe (ADR-017).
 */
class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function summary(?string $range = null, ?User $user = null): array
    {
        $query = $range !== null ? '?range='.$range : '';

        return $this->actingAs($user ?? $this->user)->fromSpa()->getJson('/api/v1/dashboard/summary'.$query)->assertOk()->json('data');
    }

    public function test_the_contract_exposes_real_counts_and_never_fabricates_audience_metrics(): void
    {
        $data = $this->summary();

        $this->assertSame(['range', 'published_count', 'scheduled_count', 'draft_count', 'failed_count', 'connected_accounts', 'impressions', 'reach', 'views', 'engagement_rate'], array_keys($data));
        $this->assertSame('7d', $data['range'], 'Période par défaut.');
        $this->assertSame([0, 0, 0, 0, 0], [$data['published_count'], $data['scheduled_count'], $data['draft_count'], $data['failed_count'], $data['connected_accounts']]);
        foreach (['impressions', 'reach', 'views', 'engagement_rate'] as $metric) {
            $this->assertNull($data[$metric], "{$metric} : non disponible (null), jamais 0.");
        }
    }

    public function test_counts_follow_the_requested_period_and_post_statuses(): void
    {
        Post::factory()->for($this->user)->published()->create(['published_at' => now()->subHours(3)]);
        Post::factory()->for($this->user)->published()->create(['published_at' => now()->subDays(5)]);
        Post::factory()->for($this->user)->published()->create(['published_at' => now()->subDays(20)]);
        Post::factory()->for($this->user)->create(['status' => 'partially_published', 'published_at' => now()->subDays(2)]);
        Post::factory()->for($this->user)->scheduled()->count(2)->create();
        Post::factory()->for($this->user)->count(3)->create(); // brouillons
        Post::factory()->for($this->user)->create(['status' => 'failed']);
        SocialAccount::factory()->for($this->user)->create();
        SocialAccount::factory()->for($this->user)->expired()->create();

        $this->assertSame(1, $this->summary('today')['published_count']);
        $this->assertSame(3, $this->summary('7d')['published_count'], 'Publiées et partiellement publiées sur 7 jours.');
        $week = $this->summary('30d');
        $this->assertSame([4, 2, 3, 2, 1], [$week['published_count'], $week['scheduled_count'], $week['draft_count'], $week['failed_count'], $week['connected_accounts']],
            'Échecs = échouées + partiellement publiées ; seuls les comptes connectés comptent.');
    }

    public function test_another_users_posts_and_accounts_are_never_counted(): void
    {
        $other = User::factory()->create();
        Post::factory()->for($other)->published()->count(4)->create(['published_at' => now()->subHour()]);
        Post::factory()->for($other)->scheduled()->create();
        SocialAccount::factory()->for($other)->count(2)->create();
        Post::factory()->for($this->user)->published()->create(['published_at' => now()->subHour()]);

        $mine = $this->summary('30d');
        $this->assertSame([1, 0, 0], [$mine['published_count'], $mine['scheduled_count'], $mine['connected_accounts']]);
        $theirs = $this->summary('30d', $other);
        $this->assertSame([4, 1, 2], [$theirs['published_count'], $theirs['scheduled_count'], $theirs['connected_accounts']]);
    }

    public function test_an_unsupported_period_is_refused_instead_of_silently_reinterpreted(): void
    {
        $this->actingAs($this->user)->fromSpa()->getJson('/api/v1/dashboard/summary?range=90d')->assertUnprocessable();
    }

    public function test_the_platform_catalogue_never_claims_statistics_without_a_collection_adapter(): void
    {
        $this->seed(SocialPlatformSeeder::class);

        $claimed = SocialPlatform::query()->get()
            ->filter(fn ($p) => in_array('supported', [$p->capabilities['account_analytics'] ?? null, $p->capabilities['post_analytics'] ?? null], true))
            ->pluck('key')->all();
        $this->assertSame([], $claimed, 'Aucun adaptateur de collecte (étape 7, P1+) : statistiques jamais annoncées « disponibles ».');

        // Catalogue servi à l'interface : même règle ; les autres capacités restent annoncées.
        $platforms = collect($this->actingAs($this->user)->fromSpa()->getJson('/api/v1/social/platforms')->assertOk()->json('data'))->keyBy('key');
        $this->assertSame('to_verify', $platforms['youtube']['capabilities']['account_analytics']);
        $this->assertSame('supported', $platforms['youtube']['capabilities']['native_scheduling']);
        $this->assertSame('supported', $platforms['instagram']['capabilities']['reel_short']);
    }
}
