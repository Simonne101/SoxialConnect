<?php

namespace Tests\Feature\Clipper;

use App\Jobs\AnalyzeClipSourceJob;
use App\Models\User;
use App\Models\VideoProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Double envoi d'une même demande de projet (double clic, requête rejouée) : le projet déjà créé est
 * renvoyé, sans doublon ni seconde analyse. Une demande différente, plus tardive ou d'un autre
 * utilisateur crée bien son propre projet.
 *
 * Horloge figée : la fenêtre réelle de 10 s est vérifiée sur le temps simulé, jamais sur la vitesse de la
 * machine (un poste lent espaçait les deux requêtes de plus de 10 s).
 */
class ClipperCreateDedupeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    private const WINDOW = 10; // règle métier réelle (config/video.php), jamais allongée ici

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 'Conférence IA', 'author_name' => 'Chaîne Test'])]);
        config(['video.clipper.create_dedupe_seconds' => self::WINDOW]);
        $this->freezeTime();
    }

    private function create(User $user, array $body = []): string
    {
        $this->app['auth']->forgetGuards();

        return (string) $this->actingAs($user)->fromSpa()->postJson('/api/v1/clipper/projects', ['youtube_url' => self::URL, 'aspect_ratio' => '9:16', ...$body])
            ->assertCreated()->json('data.id');
    }

    public function test_a_repeated_request_returns_the_same_project_without_a_second_analysis(): void
    {
        $user = User::factory()->create();
        $first = $this->create($user);
        $this->travel(self::WINDOW - 1)->seconds();
        $second = $this->create($user, ['youtube_url' => 'https://youtu.be/dQw4w9WgXcQ']); // même vidéo, autre forme du lien

        $this->assertSame($first, $second, 'Dans la fenêtre : même projet.');
        $this->assertSame(1, VideoProject::query()->where('user_id', $user->id)->count());
        Queue::assertPushed(AnalyzeClipSourceJob::class, 1);
    }

    public function test_a_request_after_the_window_creates_a_new_project(): void
    {
        $user = User::factory()->create();
        $first = $this->create($user);
        $this->travel(self::WINDOW + 1)->seconds();

        $this->assertNotSame($first, $this->create($user), 'Hors fenêtre : nouveau projet.');
        $this->assertSame(2, VideoProject::query()->where('user_id', $user->id)->count());
        Queue::assertPushed(AnalyzeClipSourceJob::class, 2);
    }

    public function test_a_different_or_foreign_request_creates_its_own_project(): void
    {
        $user = User::factory()->create();
        $first = $this->create($user);

        $this->assertNotSame($first, $this->create($user, ['aspect_ratio' => '1:1']), 'Autre format : autre projet.');
        $this->assertNotSame($first, $this->create(User::factory()->create()), 'Autre utilisateur : jamais le projet d\'un autre.');

        VideoProject::query()->whereKey($first)->update(['status' => 'cancelled']);
        $this->assertNotSame($first, $this->create($user), 'Projet annulé : jamais renvoyé.');
    }

    public function test_the_creation_lock_is_really_taken(): void
    {
        // Une autre requête de la même personne, pour la même vidéo, détient le verrou : celle-ci attend la durée
        // réelle (20 s, simulées) puis abandonne sans rien créer — le verrou n'est pas neutralisé par le test.
        Sleep::fake(syncWithCarbon: true);
        $user = User::factory()->create();
        $other = Cache::lock("clipper-create:{$user->id}:dQw4w9WgXcQ", 30);
        $this->assertTrue($other->get());
        $start = now();

        $this->actingAs($user)->fromSpa()->postJson('/api/v1/clipper/projects', ['youtube_url' => self::URL, 'aspect_ratio' => '9:16'])
            ->assertStatus(409)->assertJsonPath('code', 'ANALYSIS_RUNNING');
        $this->assertSame(0, VideoProject::query()->where('user_id', $user->id)->count());
        $this->assertGreaterThanOrEqual(19, $start->diffInSeconds(now()), 'Attente réelle du verrou (temps simulé).');

        $other->release();
        $this->create($user);
        $this->assertSame(1, VideoProject::query()->where('user_id', $user->id)->count());
    }
}
