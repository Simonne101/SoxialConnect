<?php

namespace Tests\Feature\Content;

use App\Models\Media;
use App\Models\PostMedia;
use App\Models\PostVariant;
use App\Models\PublicationAttempt;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Modifier une publication préserve ses variantes (identifiants, historique) au lieu de les
 * supprimer et recréer ; les retraits se font dans un ordre sans cascade MySQL (ADR-034, ADR-036).
 */
class PostVariantSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SocialAccount $facebook;

    private SocialAccount $linkedin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->facebook = SocialAccount::factory()->for($this->user)->create();
        $this->linkedin = SocialAccount::factory()->for($this->user)->create();
    }

    private function target(SocialAccount $account, string $format = 'feed', array $extra = []): array
    {
        return ['social_account_id' => $account->id, 'format' => $format, ...$extra];
    }

    private function save(array $payload, ?array $post = null): TestResponse
    {
        $body = ['mode' => 'draft', 'title' => 'Titre', 'body' => 'Texte', ...$payload];
        $api = $this->actingAs($this->user)->fromSpa();

        return $post === null
            ? $api->postJson('/api/v1/content/posts', $body)
            : $api->putJson("/api/v1/content/posts/{$post['id']}", [...$body, 'lock_version' => $post['lock_version']]);
    }

    /** @return array<string, string> compte → identifiant de variante */
    private function variantIds(array $post): array
    {
        return collect($post['variants'])->mapWithKeys(fn ($v) => [$v['social_account_id'] => $v['id']])->all();
    }

    private function attempt(string $variantId, int $number = 1): PublicationAttempt
    {
        return PublicationAttempt::create([
            'post_variant_id' => $variantId,
            'attempt_number' => $number,
            'idempotency_key' => hash('sha256', "{$variantId}|{$number}"),
            'status' => 'failed',
            'error_code' => 'RATE_LIMITED',
        ]);
    }

    public function test_updating_keeps_variant_ids_and_publication_history(): void
    {
        $post = $this->save(['targets' => [$this->target($this->facebook), $this->target($this->linkedin)]])->assertCreated()->json('data');
        $before = $this->variantIds($post);
        $attempt = $this->attempt($before[$this->facebook->id]);

        $updated = $this->save([
            'body' => 'Texte modifié',
            'targets' => [$this->target($this->facebook, 'feed', ['body' => 'Version Facebook']), $this->target($this->linkedin)],
        ], $post)->assertOk()->json('data');

        $this->assertSame($before, $this->variantIds($updated));
        $this->assertSame('Version Facebook', collect($updated['variants'])->firstWhere('id', $before[$this->facebook->id])['body']);
        $this->assertDatabaseHas('publication_attempts', ['id' => $attempt->id]);
    }

    public function test_format_change_on_the_same_account_keeps_the_variant_id(): void
    {
        $post = $this->save(['targets' => [$this->target($this->facebook, 'feed')]])->json('data');
        $id = $post['variants'][0]['id'];

        $updated = $this->save(['targets' => [$this->target($this->facebook, 'story')]], $post)->assertOk()->json('data');

        $this->assertSame($id, $updated['variants'][0]['id']);
        $this->assertSame('story', $updated['variants'][0]['format']);
    }

    public function test_removing_a_target_deletes_only_that_variant_in_a_safe_order(): void
    {
        $post = $this->save(['targets' => [$this->target($this->facebook), $this->target($this->linkedin)]])->json('data');
        $ids = $this->variantIds($post);
        // Dépendances de la variante retirée : tentative + média propre à la variante (post_media a une
        // colonne générée virtuelle indexée : c'est ce cas qui ne doit pas passer par une cascade).
        $attempt = $this->attempt($ids[$this->linkedin->id]);
        $media = Media::factory()->for($this->user)->create();
        $variantMedia = PostMedia::create(['post_id' => $post['id'], 'post_variant_id' => $ids[$this->linkedin->id], 'media_id' => $media->id, 'position' => 0]);
        $keptAttempt = $this->attempt($ids[$this->facebook->id]);

        $updated = $this->save(['targets' => [$this->target($this->facebook)]], $post)->assertOk()->json('data');

        $this->assertSame([$this->facebook->id => $ids[$this->facebook->id]], $this->variantIds($updated));
        $this->assertDatabaseMissing('post_variants', ['id' => $ids[$this->linkedin->id]]);
        $this->assertDatabaseMissing('publication_attempts', ['id' => $attempt->id]);
        $this->assertDatabaseMissing('post_media', ['id' => $variantMedia->id]);
        $this->assertDatabaseHas('publication_attempts', ['id' => $keptAttempt->id]);
        $this->assertNotSoftDeleted('media', ['id' => $media->id]);
        $this->assertSame(1, (int) DB::select('select 1 as ok')[0]->ok, 'Le serveur MySQL répond toujours.');
    }

    public function test_adding_a_target_creates_a_variant_and_keeps_the_others(): void
    {
        $post = $this->save(['targets' => [$this->target($this->facebook)]])->json('data');
        $facebookId = $post['variants'][0]['id'];

        $updated = $this->save(['targets' => [$this->target($this->facebook), $this->target($this->linkedin)]], $post)->assertOk()->json('data');

        $ids = $this->variantIds($updated);
        $this->assertCount(2, $ids);
        $this->assertSame($facebookId, $ids[$this->facebook->id]);
    }

    public function test_media_links_are_synchronised_without_being_recreated(): void
    {
        [$a, $b, $c] = Media::factory()->count(3)->for($this->user)->create()->all();
        $post = $this->save(['media_ids' => [$a->id, $b->id], 'targets' => [$this->target($this->facebook)]])->json('data');
        $linkA = PostMedia::where('post_id', $post['id'])->where('media_id', $a->id)->firstOrFail();
        $variantMedia = PostMedia::create(['post_id' => $post['id'], 'post_variant_id' => $post['variants'][0]['id'], 'media_id' => $c->id, 'position' => 0]);

        // Réordonner, retirer B, ajouter C en commun.
        $updated = $this->save(['media_ids' => [$c->id, $a->id], 'targets' => [$this->target($this->facebook)]], $post)->assertOk()->json('data');

        $this->assertSame([$c->id, $a->id], $updated['media_ids']);
        $this->assertSame($linkA->id, PostMedia::where('post_id', $post['id'])->whereNull('post_variant_id')->where('media_id', $a->id)->value('id'));
        $this->assertSame(1, PostMedia::where('post_id', $post['id'])->whereNull('post_variant_id')->where('media_id', $a->id)->value('position'));
        $this->assertDatabaseMissing('post_media', ['post_id' => $post['id'], 'media_id' => $b->id]);
        $this->assertDatabaseHas('post_media', ['id' => $variantMedia->id]); // média propre à la variante : intact
    }

    public function test_status_transitions_update_variants_in_place_and_clear_the_current_error(): void
    {
        $post = $this->save(['targets' => [$this->target($this->facebook)]])->json('data');
        $id = $post['variants'][0]['id'];
        PostVariant::whereKey($id)->update(['last_error_code' => 'RATE_LIMITED', 'last_error_message' => 'Trop de requêtes.']);

        $scheduledAt = now()->addDay()->startOfMinute();
        $scheduled = $this->save(['mode' => 'schedule', 'scheduled_at' => $scheduledAt->toIso8601String(), 'targets' => [$this->target($this->facebook)]], $post)
            ->assertOk()->json('data');
        $this->assertSame($id, $scheduled['variants'][0]['id']);
        $this->assertSame('scheduled', $scheduled['variants'][0]['status']);
        $this->assertSame($scheduledAt->utc()->toIso8601String(), $scheduled['variants'][0]['scheduled_at']);
        $this->assertNull($scheduled['variants'][0]['error_message']);

        $draft = $this->save(['targets' => [$this->target($this->facebook)]], $scheduled)->assertOk()->json('data');
        $this->assertSame($id, $draft['variants'][0]['id']);
        $this->assertSame('draft', $draft['variants'][0]['status']);
        $this->assertNull($draft['variants'][0]['scheduled_at']);
    }

    public function test_duplicate_targets_are_rejected_with_422_not_500(): void
    {
        $this->save(['targets' => [$this->target($this->facebook), $this->target($this->facebook)]])
            ->assertUnprocessable()->assertJsonValidationErrors(['targets.1.social_account_id']);

        // Même compte avec deux formats différents : autorisé.
        $this->save(['targets' => [$this->target($this->facebook, 'feed'), $this->target($this->facebook, 'story')]])->assertCreated()->assertJsonCount(2, 'data.variants');
    }
}
