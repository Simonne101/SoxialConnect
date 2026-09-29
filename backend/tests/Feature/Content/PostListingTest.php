<?php

namespace Tests\Feature\Content;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\SocialPlatform;
use App\Models\User;
use Database\Seeders\SocialPlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Liste des publications : pagination, recherche, filtres, calendrier (from/to), compteurs. */
class PostListingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SocialPlatformSeeder::class);
        $this->user = User::factory()->create();
    }

    private function list(string $query = ''): TestResponse
    {
        return $this->actingAs($this->user)->fromSpa()->getJson('/api/v1/content/posts'.($query !== '' ? "?{$query}" : ''));
    }

    private function onPlatform(Post $post, string $key): Post
    {
        $account = SocialAccount::factory()->for($this->user)->create([
            'social_platform_id' => SocialPlatform::query()->where('key', $key)->value('id'),
        ]);
        $post->variants()->create(['social_account_id' => $account->id, 'format' => 'feed']);

        return $post;
    }

    public function test_pagination_meta_and_pages_do_not_overlap(): void
    {
        Post::factory()->count(5)->for($this->user)->create();

        $first = $this->list('per_page=2&page=1')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
        $second = $this->list('per_page=2&page=2')->assertOk()->assertJsonCount(2, 'data');
        $third = $this->list('per_page=2&page=3')->assertOk()->assertJsonCount(1, 'data');

        $ids = collect([$first, $second, $third])->flatMap(fn ($r) => $r->json('data.*.id'));
        $this->assertCount(5, $ids->unique());

        // Au-delà de la dernière page : liste vide, pas d'erreur.
        $this->list('per_page=2&page=9')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_default_page_size_and_limits(): void
    {
        $this->list()->assertOk()->assertJsonPath('meta.per_page', 25);
        $this->list('per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->list('per_page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->list('page=0')->assertUnprocessable()->assertJsonValidationErrors(['page']);
    }

    public function test_status_filter_accepts_array_and_single_value(): void
    {
        Post::factory()->count(2)->for($this->user)->create();
        Post::factory()->for($this->user)->scheduled()->create();
        Post::factory()->for($this->user)->published()->create();

        $this->list('status=draft')->assertOk()->assertJsonCount(2, 'data');
        $this->list('status[]=scheduled&status[]=published')->assertOk()->assertJsonCount(2, 'data');
        $this->list('status[]=unknown')->assertUnprocessable()->assertJsonValidationErrors(['status.0']);
    }

    public function test_status_counts_ignore_the_status_filter_but_follow_other_filters(): void
    {
        Post::factory()->count(2)->for($this->user)->create(['title' => 'Recette du jour']);
        Post::factory()->for($this->user)->scheduled()->create(['title' => 'Recette du soir']);
        Post::factory()->for($this->user)->scheduled()->create(['title' => 'Autre sujet']);

        $this->list('status=draft')->assertOk()
            ->assertJsonPath('meta.status_counts.draft', 2)
            ->assertJsonPath('meta.status_counts.scheduled', 2)
            ->assertJsonPath('meta.status_counts.published', 0);

        $this->list('search=recette')->assertOk()
            ->assertJsonPath('meta.status_counts.draft', 2)
            ->assertJsonPath('meta.status_counts.scheduled', 1);
    }

    public function test_search_matches_title_or_body_and_escapes_wildcards(): void
    {
        Post::factory()->for($this->user)->create(['title' => 'Lancement', 'body' => 'Nouvelle collection printemps']);
        Post::factory()->for($this->user)->create(['title' => 'Promo 100%', 'body' => 'Offre']);
        Post::factory()->for($this->user)->create(['title' => 'Autre', 'body' => 'Rien à voir']);

        $this->list('search=printemps')->assertOk()->assertJsonCount(1, 'data');
        $this->list('search=LANCEMENT')->assertOk()->assertJsonCount(1, 'data');
        // « % » est cherché littéralement, pas comme joker SQL.
        $this->list('search='.urlencode('%'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Promo 100%');
        $this->list('search='.str_repeat('a', 101))->assertUnprocessable()->assertJsonValidationErrors(['search']);
    }

    public function test_platform_filter(): void
    {
        $this->onPlatform(Post::factory()->for($this->user)->create(), 'facebook');
        $this->onPlatform(Post::factory()->for($this->user)->create(), 'instagram');
        Post::factory()->for($this->user)->create();

        $this->list('platform=facebook')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.variants.0.platform', 'facebook');
        $this->list('platform=instagram')->assertOk()->assertJsonCount(1, 'data');
        $this->list('platform=myspace')->assertUnprocessable()->assertJsonValidationErrors(['platform']);
    }

    public function test_calendar_range_from_to(): void
    {
        Post::factory()->for($this->user)->scheduled()->create(['scheduled_at' => '2031-03-10 09:00:00']);
        Post::factory()->for($this->user)->scheduled()->create(['scheduled_at' => '2031-03-31 21:00:00']);
        Post::factory()->for($this->user)->scheduled()->create(['scheduled_at' => '2031-04-02 09:00:00']);
        Post::factory()->for($this->user)->create(); // brouillon sans date : hors calendrier

        $range = 'from='.urlencode('2031-03-01T00:00:00+00:00').'&to='.urlencode('2031-03-31T23:59:59+00:00');
        $this->list($range)->assertOk()->assertJsonCount(2, 'data');

        // Les bornes sont interprétées avec leur fuseau (stockage en UTC).
        $this->list('from='.urlencode('2031-04-01T00:00:00+02:00').'&to='.urlencode('2031-04-30T23:59:59+02:00'))
            ->assertOk()->assertJsonCount(1, 'data');

        $this->list('from=2031-04-01&to=2031-03-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->list('from=pas-une-date')->assertUnprocessable()->assertJsonValidationErrors(['from']);
    }

    public function test_results_are_ordered_by_date_descending(): void
    {
        $older = Post::factory()->for($this->user)->scheduled()->create(['scheduled_at' => now()->addDays(2)]);
        $newer = Post::factory()->for($this->user)->scheduled()->create(['scheduled_at' => now()->addDays(5)]);

        $this->list()->assertOk()->assertJsonPath('data.0.id', $newer->id)->assertJsonPath('data.1.id', $older->id);
    }

    public function test_http_codes_for_guests_and_unverified_users(): void
    {
        $this->fromSpa()->getJson('/api/v1/content/posts')->assertUnauthorized();

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->fromSpa()->getJson('/api/v1/content/posts')->assertForbidden();
    }
}
