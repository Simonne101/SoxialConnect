<?php

namespace Tests\Feature\Clipper;

use App\Domain\Video\Clipper\ClipLimits;
use App\Jobs\AnalyzeClipSourceJob;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoClip;
use App\Models\VideoProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Parcours URL-first du Clipper (ADR-048) : le lien suffit pour identifier la vidéo, créer le projet
 * et — avec un fournisseur compatible (Gemini, simulé ici) — proposer des passages. La vidéo source
 * rendable n'est exigée que pour le rendu. Aucune requête réelle.
 */
class ClipperUrlFlowTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    private const GEMINI = 'generativelanguage.googleapis.com/v1beta/interactions';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config([
            'ai.default_provider' => 'openai',
            'ai.providers.openai.api_key' => 'sk-test-cle-fictive',
            'ai.tasks.video_analysis.provider' => 'gemini',
            'ai.providers.gemini.api_key' => 'gemini-cle-fictive',
            'ai.providers.gemini.models' => ['video_analysis' => 'gemini-analyse-test', 'image' => null],
            'video.clipper.analysis' => 'auto',
        ]);
        $this->user = User::factory()->create();
    }

    private function api(?User $user = null): static
    {
        return $this->actingAs($user ?? $this->user)->fromSpa();
    }

    private function oembed(?string $thumbnail = 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'): void
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 'Conférence IA', 'author_name' => 'Chaîne Test', 'thumbnail_url' => $thumbnail])]);
    }

    private function geminiClips(array $clips): void
    {
        Http::fake([self::GEMINI => Http::response(['id' => 'v1_x', 'status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => json_encode(['clips' => $clips])]]]], 'usage' => ['total_input_tokens' => 9000, 'total_output_tokens' => 200]])]);
    }

    private function urlProject(): VideoProject
    {
        return VideoProject::factory()->for($this->user)->clipping()->create(['source_url' => self::URL, 'title' => 'Conférence IA', 'source_media_id' => null]);
    }

    private function analyze(VideoProject $project): VideoProject
    {
        app()->call([new AnalyzeClipSourceJob($project->id), 'handle']);

        return $project->fresh();
    }

    public function test_a_link_alone_identifies_the_video_and_creates_the_project(): void
    {
        $this->oembed();

        $this->api()->postJson('/api/v1/clipper/lookup', ['youtube_url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertOk()
            ->assertJsonPath('data.video_id', 'dQw4w9WgXcQ')->assertJsonPath('data.url', self::URL)
            ->assertJsonPath('data.title', 'Conférence IA')->assertJsonPath('data.author', 'Chaîne Test')
            ->assertJsonPath('data.thumbnail', 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg');
        $this->api()->postJson('/api/v1/clipper/lookup', ['youtube_url' => 'https://evil.test/watch?v=dQw4w9WgXcQ'])->assertStatus(422)->assertJsonPath('code', 'INVALID_URL');

        $id = $this->api()->postJson('/api/v1/clipper/projects', ['youtube_url' => self::URL, 'aspect_ratio' => '9:16'])
            ->assertCreated()->assertJsonPath('data.renderable', false)->assertJsonPath('data.source', null)
            ->assertJsonPath('data.youtube_thumbnail', 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg')
            ->assertJsonPath('meta.url_analysis_available', true)->assertJsonPath('meta.url_analysis_provider', 'gemini')
            ->json('data.id');
        $this->assertDatabaseHas('video_projects', ['id' => $id, 'source_media_id' => null, 'source_type' => 'url']);
        Queue::assertPushed(AnalyzeClipSourceJob::class, fn ($job) => $job->projectId === $id);
    }

    public function test_a_thumbnail_outside_the_youtube_image_cdn_is_never_exposed(): void
    {
        $this->oembed('https://evil.test/pixel.png');

        $this->api()->postJson('/api/v1/clipper/lookup', ['youtube_url' => self::URL])->assertOk()->assertJsonPath('data.thumbnail', null);
    }

    public function test_gemini_proposes_passages_from_the_link_validated_by_the_server(): void
    {
        $this->geminiClips([
            ['start_ms' => 34_000, 'end_ms' => 78_000, 'title' => 'L\'accroche', 'hook' => 'Le moment clé', 'topic' => 'Le sujet', 'reason' => 'Fort', 'confidence' => 88, 'criteria' => ['hook' => 90, 'value' => 85, 'autonomy' => 80, 'emotion' => 70, 'rhythm' => 75, 'quality' => 80]],
            ['start_ms' => 130_000, 'end_ms' => 190_000, 'title' => 'Soixante secondes', 'hook' => 'h', 'topic' => 't', 'reason' => 'r', 'confidence' => 70, 'criteria' => ['hook' => 90, 'value' => 85, 'autonomy' => 80, 'emotion' => 70, 'rhythm' => 75, 'quality' => 80]],
            ['start_ms' => 200_000, 'end_ms' => 280_000, 'title' => 'Trop long', 'hook' => 'h', 'topic' => 't', 'reason' => 'r', 'confidence' => 70, 'criteria' => ['hook' => 90, 'value' => 85, 'autonomy' => 80, 'emotion' => 70, 'rhythm' => 75, 'quality' => 80]],
            ['start_ms' => -5, 'end_ms' => 'NaN', 'title' => 'Invalide', 'hook' => 'h', 'topic' => 't', 'reason' => 'r', 'confidence' => 99, 'criteria' => ['hook' => 90, 'value' => 85, 'autonomy' => 80, 'emotion' => 70, 'rhythm' => 75, 'quality' => 80]],
        ]);

        $project = $this->analyze($this->urlProject());

        $this->assertSame('ready', $project->status->value);
        $this->assertSame(['ai', 'youtube_url'], [$project->settings['analysis']['mode'], $project->settings['analysis']['input']]);
        $clips = VideoClip::query()->where('video_project_id', $project->id)->orderBy('start_ms')->get();
        $this->assertCount(2, $clips, 'Réponse invalide et passage de plus de 60 s écartés.');
        $this->assertSame([130_000, 190_000], [$clips[1]->start_ms, $clips[1]->end_ms]);
        $this->assertSame('Le moment clé', $clips[0]->hook);
        $this->assertSame(88, $clips[0]->confidence);
        $this->assertEquals(82.25, $clips[0]->score, 'Score global calculé par le backend (poids configurés), jamais repris de l\'IA.');
        foreach ($clips as $clip) {
            $this->assertSame('ai', $clip->source->value);
            $this->assertLessThanOrEqual(ClipLimits::MAX_CLIP_MS, $clip->end_ms - $clip->start_ms);
        }
        $this->assertNull($project->duration_ms, 'Durée réelle inconnue tant que la source n\'est pas jointe.');
        $this->assertDatabaseHas('ai_generations', ['video_project_id' => $project->id, 'task' => 'video_analysis', 'provider' => 'gemini', 'model' => 'gemini-analyse-test', 'status' => 'succeeded']);
        Http::assertSent(fn (Request $r) => $r['input'][1] === ['type' => 'video', 'uri' => self::URL] && $r->hasHeader('x-goog-api-key', 'gemini-cle-fictive'));

        // Rendu impossible sans source rendable : refus explicite.
        $this->api()->postJson("/api/v1/clipper/clips/{$clips[0]->id}/render")->assertStatus(409)->assertJsonPath('code', 'SOURCE_REQUIRED');
    }

    public function test_without_a_compatible_provider_the_project_ends_cleanly_and_asks_for_the_source(): void
    {
        config(['ai.tasks.video_analysis.provider' => null]); // OpenAI seul : pas d'analyse par URL

        $project = $this->analyze($this->urlProject());

        $this->assertSame('ready', $project->status->value);
        $this->assertSame('none', $project->settings['analysis']['mode']);
        $this->assertStringContainsString('Gemini', implode(' ', $project->settings['analysis']['notes']));
        Http::assertNothingSent();
        $this->api()->getJson("/api/v1/clipper/projects/{$project->id}")->assertOk()
            ->assertJsonPath('data.renderable', false)->assertJsonPath('meta.url_analysis_available', false);
    }

    public function test_an_exhausted_ai_quota_is_not_a_failure_of_the_studio(): void
    {
        Http::fake([self::GEMINI => Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded']], 429)]);

        $project = $this->analyze($this->urlProject());

        $this->assertSame('ready', $project->status->value);
        $this->assertSame('QUOTA_EXCEEDED', $project->settings['analysis']['fallback']);
        $this->assertDatabaseCount('video_clips', 0);
        $this->assertDatabaseHas('ai_generations', ['video_project_id' => $project->id, 'status' => 'failed']);
    }

    public function test_an_exhausted_url_analysis_quota_is_not_retried_for_every_new_project(): void
    {
        Http::fake([self::GEMINI => Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded']], 429)]);
        $gemini = fn () => Http::recorded(fn ($request) => str_contains($request->url(), 'generativelanguage.googleapis.com'))->count();

        $this->analyze($this->urlProject());
        $this->assertSame(1, $gemini());

        // Même quota épuisé : aucun nouvel appel voué à l'échec (7 échecs identiques constatés le 2026-10-13).
        $second = $this->analyze($this->urlProject());
        $this->assertSame(1, $gemini(), 'Analyse par lien suspendue pendant 30 minutes.');
        $this->assertSame('QUOTA_EXCEEDED', $second->settings['analysis']['fallback']);
        $this->assertStringContainsString('suspendue', implode(' ', $second->settings['analysis']['notes']));

        // Délai écoulé : nouvel essai réel.
        $this->travel(31)->minutes();
        $this->analyze($this->urlProject());
        $this->assertSame(2, $gemini());
    }

    public function test_an_unusable_ai_answer_is_never_reported_as_no_passage(): void
    {
        $clip = fn (array $o = []) => [...['start_ms' => 1000, 'end_ms' => 9000, 'title' => 'T', 'hook' => 'Accroche', 'topic' => 'S', 'reason' => 'R', 'confidence' => 80,
            'criteria' => ['hook' => 80, 'value' => 80, 'autonomy' => 80, 'emotion' => 80, 'rhythm' => 80, 'quality' => 80]], ...$o];
        $cases = [
            // [texte, statut Gemini, code attendu, extrait de la note]
            ['', 'completed', 'INVALID_RESPONSE', 'illisible'],
            ['Voici mes idées : pas de JSON ici.', 'completed', 'INVALID_RESPONSE', 'illisible'],
            ['{"clips": "pas une liste"}', 'completed', 'INVALID_RESPONSE', 'illisible'],
            ['{"clips":[{"start_ms":1000,"end_ms":9000,"title":"Coupé net', 'incomplete', 'INCOMPLETE_RESPONSE', 'interrompue'],
            [json_encode(['clips' => [['start_ms' => 'x', 'end_ms' => 5000]]]), 'completed', 'NO_VALID_CLIPS', '1 passage(s), tous écartés par les règles du Clipper (1 incomplets)'],
            [json_encode(['clips' => [$clip(['end_ms' => 90_000]), $clip(['start_ms' => 2000, 'end_ms' => 75_000])]]), 'completed', 'NO_VALID_CLIPS', '2 passage(s), tous écartés par les règles du Clipper (2 de plus de 60 s)'],
            [json_encode(['clips' => []]), 'completed', null, 'aucun passage pertinent'],
        ];
        foreach ($cases as [$text, $status, $code, $note]) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake([self::GEMINI => Http::response(['id' => 'v1_x', 'status' => $status, 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => $text]]]]])]);

            $project = $this->analyze($this->urlProject());

            $this->assertSame('ready', $project->status->value, $text);
            $this->assertSame(0, VideoClip::query()->where('video_project_id', $project->id)->count(), $text);
            $this->assertSame($code, $project->settings['analysis']['fallback'], $text);
            $notes = implode(' ', $project->settings['analysis']['notes']);
            $this->assertStringContainsString($note, $notes, $text);
            if ($code !== null) {
                $this->assertStringNotContainsString('aucun passage pertinent', $notes, 'Une réponse inexploitable n\'est jamais présentée comme « aucun passage ».');
            }
        }
    }

    public function test_an_unavailable_gemini_model_falls_back_once_to_the_configured_model_explicitly(): void
    {
        $clip = ['start_ms' => 1000, 'end_ms' => 9000, 'title' => 'T', 'hook' => 'Accroche', 'topic' => 'S', 'reason' => 'R', 'confidence' => 80,
            'criteria' => ['hook' => 80, 'value' => 80, 'autonomy' => 80, 'emotion' => 80, 'rhythm' => 80, 'quality' => 80]];
        $quota = Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'You exceeded your current quota (20 requests per day on Free Tier).']], 429);
        $ok = Http::response(['id' => 'v1_x', 'status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => json_encode(['clips' => [$clip]])]]]]]);

        // Sans modèle de secours : l'échec du principal est rapporté tel quel (une seule requête).
        Http::fake([self::GEMINI => Http::sequence()->pushResponse($quota)]);
        $project = $this->analyze($this->urlProject());
        $this->assertSame('QUOTA_EXCEEDED', $project->settings['analysis']['fallback']);
        Http::assertSentCount(1);

        // Avec un modèle de secours : une seule nouvelle tentative, tracée et signalée.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        config(['ai.tasks.video_analysis.url_fallback_model' => 'gemini-secours']);
        Http::fake([self::GEMINI => Http::sequence()->pushResponse($quota)->pushResponse($ok)]);
        $project = $this->analyze($this->urlProject());

        $this->assertSame(1, VideoClip::query()->where('video_project_id', $project->id)->count());
        $this->assertSame('gemini-secours', $project->settings['analysis']['fallback_model']);
        $this->assertStringContainsString('modèle de secours gemini-secours (modèle principal indisponible : QUOTA_EXCEEDED)', implode(' ', $project->settings['analysis']['notes']));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), self::GEMINI) && $r['model'] === 'gemini-analyse-test');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), self::GEMINI) && $r['model'] === 'gemini-secours');
        $this->assertDatabaseHas('ai_generations', ['video_project_id' => $project->id, 'model' => 'gemini-analyse-test', 'status' => 'failed']);
        $this->assertDatabaseHas('ai_generations', ['video_project_id' => $project->id, 'model' => 'gemini-secours', 'status' => 'succeeded']);
    }

    public function test_links_can_be_analysed_by_gemini_while_transcripts_use_another_provider(): void
    {
        config([
            'ai.tasks.video_analysis.provider' => 'openai',
            'ai.tasks.video_analysis.model' => 'gpt-test',
            'ai.tasks.video_analysis.url_provider' => 'gemini',
        ]);
        $this->geminiClips([['start_ms' => 1000, 'end_ms' => 9000, 'title' => 'T', 'hook' => 'Accroche', 'topic' => 'S', 'reason' => 'R', 'confidence' => 80,
            'criteria' => ['hook' => 80, 'value' => 80, 'autonomy' => 80, 'emotion' => 80, 'rhythm' => 80, 'quality' => 80]]]);

        $project = $this->analyze($this->urlProject());

        $this->assertSame(1, VideoClip::query()->where('video_project_id', $project->id)->count());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), self::GEMINI) && $r['model'] === 'gemini-analyse-test');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.openai.com'));
        $this->assertDatabaseHas('ai_generations', ['video_project_id' => $project->id, 'provider' => 'gemini', 'model' => 'gemini-analyse-test']);
    }

    public function test_a_temporary_ai_outage_can_be_retried_on_the_same_project_without_duplicates(): void
    {
        Sleep::fake();
        Http::fake([self::GEMINI => Http::response(['error' => ['message' => 'gemini is currently experiencing high demand', 'code' => 'service_unavailable']], 503)]);
        $project = $this->analyze($this->urlProject());
        $this->assertSame('PROVIDER_UNAVAILABLE', $project->settings['analysis']['fallback']);
        $this->assertStringNotContainsString('high demand', implode(' ', $project->settings['analysis']['notes']), 'Jamais le message brut du fournisseur.');

        // Clip manuel déjà présent : jamais supprimé par une relance.
        $manual = new VideoClip(['title' => 'Mon passage', 'start_ms' => 0, 'end_ms' => 10_000, 'aspect_ratio' => '9:16']);
        $manual->video_project_id = $project->id;
        $manual->forceFill(['source' => 'manual', 'status' => 'rendered'])->save();

        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/reanalyze")->assertStatus(202)->assertJsonPath('data.status', 'pending');
        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/reanalyze")->assertStatus(409)->assertJsonPath('code', 'ANALYSIS_RUNNING');

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->geminiClips([['start_ms' => 10_000, 'end_ms' => 35_000, 'title' => 'Le meilleur moment', 'hook' => 'Accroche', 'topic' => 'IA', 'reason' => 'Accroche forte', 'confidence' => 90, 'criteria' => ['hook' => 90, 'value' => 85, 'autonomy' => 80, 'emotion' => 70, 'rhythm' => 75, 'quality' => 80]]]);
        $project = $this->analyze($project);
        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/reanalyze")->assertStatus(202);
        $project = $this->analyze($project);

        $this->assertSame('ready', $project->status->value);
        $this->assertSame(1, VideoProject::query()->count(), 'Même projet, jamais recréé.');
        $this->assertSame(['Le meilleur moment'], VideoClip::query()->where('video_project_id', $project->id)->where('status', 'suggested')->pluck('title')->all(), 'Aucun doublon après deux relances.');
        $this->assertTrue($manual->fresh()->exists);
        $this->api(User::factory()->create())->postJson("/api/v1/clipper/projects/{$project->id}/reanalyze")->assertNotFound();
    }

    public function test_oembed_redirects_are_never_followed(): void
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);

        $this->api()->postJson('/api/v1/clipper/lookup', ['youtube_url' => self::URL])->assertStatus(422)->assertJsonPath('code', 'VIDEO_UNAVAILABLE');
        Http::assertSentCount(1);
    }

    public function test_the_renderable_source_is_attached_later_by_its_owner_only(): void
    {
        $project = $this->urlProject();
        $project->forceFill(['status' => 'ready'])->save();
        $video = Media::factory()->for($this->user)->create(['type' => 'video']);
        $foreign = Media::factory()->for(User::factory())->create(['type' => 'video']);
        $image = Media::factory()->for($this->user)->create(['type' => 'image']);

        $this->api()->putJson("/api/v1/clipper/projects/{$project->id}/source", ['media_id' => $foreign->id])->assertStatus(422)->assertJsonPath('code', 'SOURCE_INVALID');
        $this->api()->putJson("/api/v1/clipper/projects/{$project->id}/source", ['media_id' => $image->id])->assertStatus(422);
        $this->api(User::factory()->create())->putJson("/api/v1/clipper/projects/{$project->id}/source", ['media_id' => $video->id])->assertNotFound();

        $this->api()->putJson("/api/v1/clipper/projects/{$project->id}/source", ['media_id' => $video->id])
            ->assertOk()->assertJsonPath('data.renderable', true)->assertJsonPath('data.status', 'pending');
        Queue::assertPushed(AnalyzeClipSourceJob::class, fn ($job) => $job->projectId === $project->id);
        $this->api()->putJson("/api/v1/clipper/projects/{$project->id}/source", ['media_id' => $video->id])->assertStatus(409)->assertJsonPath('code', 'ANALYSIS_RUNNING');
    }
}
