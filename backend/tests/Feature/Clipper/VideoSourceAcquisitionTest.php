<?php

namespace Tests\Feature\Clipper;

use App\Domain\Video\Clipper\ClipperService;
use App\Domain\Video\Enums\AcquisitionMethod;
use App\Domain\Video\Enums\SourceOwnership;
use App\Domain\Video\Enums\VideoSourceStatus;
use App\Domain\Video\Sources\AcquisitionStep;
use App\Domain\Video\Sources\VideoSourceProvider;
use App\Domain\Video\Sources\VideoSourceService;
use App\Jobs\AcquireVideoSourceJob;
use App\Jobs\AnalyzeClipSourceJob;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoClip;
use App\Models\VideoProject;
use App\Models\VideoSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Social\Concerns\YouTubeFixtures;
use Tests\TestCase;

/**
 * Acquisition de la source vidéo du Clipper (ADR-049) : référence YouTube séparée du fichier rendable,
 * propriété vérifiée par l'OAuth YouTube (HTTP simulé), fournisseurs pilotés par un double de test
 * (ScriptedSourceProvider) pour les états — aucun fournisseur réel n'est activé dans le produit.
 */
class VideoSourceAcquisitionTest extends TestCase
{
    use RefreshDatabase, YouTubeFixtures;

    private const URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('media');
        Http::preventStrayRequests();
        ScriptedSourceProvider::$steps = [];
        ScriptedSourceProvider::$calls = [];
        // Projets successifs sur le même lien traités isolément ; la réutilisation est couverte par VideoSourceReuseTest.
        config(['video.sources.reuse_library' => false]);
        $this->user = User::factory()->create();
    }

    private function api(): static
    {
        return $this->actingAs($this->user)->fromSpa();
    }

    private function create(): VideoProject
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 'Conférence IA', 'author_name' => 'Chaîne Test', 'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'])]);
        $id = $this->api()->postJson('/api/v1/clipper/projects', ['youtube_url' => self::URL, 'aspect_ratio' => '9:16'])->assertCreated()
            ->assertJsonPath('data.source_status.status', 'pending')->json('data.id');

        return VideoProject::findOrFail($id);
    }

    private function acquire(VideoProject $project): VideoSource
    {
        app(VideoSourceService::class)->acquire((string) $project->videoSource()->value('id'));

        return $project->videoSource()->firstOrFail();
    }

    private function video(array $attributes = []): Media
    {
        return Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'size_bytes' => 4096, 'duration_ms' => 90_000, ...$attributes]);
    }

    private function useProvider(): void
    {
        config(['video.sources.providers' => [ScriptedSourceProvider::class]]);
    }

    public function test_the_link_alone_creates_a_separate_source_and_starts_acquisition_and_analysis(): void
    {
        $project = $this->create();

        $source = $project->videoSource;
        $this->assertSame('youtube', $source->provider);
        $this->assertSame('dQw4w9WgXcQ', $source->external_reference);
        $this->assertSame('youtube_video', $source->source_type);
        $this->assertSame(VideoSourceStatus::Pending, $source->status);
        $this->assertNull($source->media_id, 'La référence YouTube n\'est jamais confondue avec un fichier.');
        $this->assertNull($project->source_media_id);
        Queue::assertPushed(AcquireVideoSourceJob::class, fn ($job) => $job->sourceId === $source->id);
        Queue::assertPushed(AnalyzeClipSourceJob::class, 1);

        $this->api()->getJson('/api/v1/clipper/projects')->assertOk()->assertJsonPath('data.0.source_status.status', 'pending');
        $this->api()->getJson("/api/v1/clipper/projects/{$project->id}")->assertOk()
            ->assertJsonPath('data.renderable', false)
            ->assertJsonPath('data.source_status.status', 'pending')
            ->assertJsonPath('meta.automatic_source_available', false);
    }

    public function test_without_any_configured_provider_the_source_is_explicitly_unavailable(): void
    {
        $project = $this->create();
        $source = $this->acquire($project);

        $this->assertSame(VideoSourceStatus::Unavailable, $source->status);
        $this->assertSame('VIDEO_DOWNLOAD_DISABLED', $source->error_code, 'Récupération yt-dlp enregistrée mais désactivée : diagnostic distinct.');
        $this->assertSame(SourceOwnership::Unverified, $source->ownership_status);
        $this->assertSame('NO_YOUTUBE_ACCOUNT', $source->metadata['ownership_reason']);
        $this->assertNull($source->media_id);
        $this->api()->getJson("/api/v1/clipper/projects/{$project->id}")
            ->assertJsonPath('data.source_status.status', 'unavailable')
            ->assertJsonPath('data.source_status.error_code', 'VIDEO_DOWNLOAD_DISABLED');
    }

    public function test_ownership_is_checked_with_the_connected_youtube_channel(): void
    {
        $project = $this->create();
        $account = $this->youtubeAccount($this->user);
        Http::fake(['www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [['snippet' => ['channelId' => 'UC_test_channel']]]])]);

        $source = $this->acquire($project);
        $this->assertSame(SourceOwnership::Owned, $source->ownership_status);
        $this->assertSame($account->id, $source->ownership_account_id);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'youtube/v3/videos') && $request['id'] === 'dQw4w9WgXcQ' && $request['part'] === 'snippet' && $request->hasHeader('Authorization', 'Bearer '.self::ACCESS_TOKEN));

        // Vidéo d'une autre chaîne (faux HTTP remplacé : les faux successifs s'ajoutent sinon).
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $other = $this->create();
        Http::fake(['www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [['snippet' => ['channelId' => 'UC_autre']]]])]);
        $this->assertSame(SourceOwnership::NotOwned, $this->acquire($other)->ownership_status);
    }

    public function test_a_waiting_provider_is_polled_by_delayed_jobs_then_bounded(): void
    {
        $this->useProvider();
        config(['video.sources.max_attempts' => 3]);
        $project = $this->create();
        ScriptedSourceProvider::$steps = [AcquisitionStep::waiting(120, ['job' => 'abc']), AcquisitionStep::waiting(120), AcquisitionStep::waiting(120)];

        $source = $this->acquire($project);
        $this->assertSame(VideoSourceStatus::WaitingForProvider, $source->status);
        $this->assertSame('scripted', $source->metadata['provider']);
        $this->assertSame('abc', $source->metadata['job']);
        $this->assertSame(1, $source->attempts);
        Queue::assertPushed(AcquireVideoSourceJob::class, fn ($job) => $job->sourceId === $source->id && $job->delay !== null);

        $source = $this->acquire($project);
        $this->assertSame(['begin', 'poll'], ScriptedSourceProvider::$calls, 'Une acquisition en attente est suivie, jamais recommencée.');
        $source = $this->acquire($project);
        $this->assertSame(VideoSourceStatus::Failed, $source->status);
        $this->assertSame('ACQUISITION_TIMEOUT', $source->error_code);
    }

    public function test_provider_failures_are_normalized_and_never_reported_as_success(): void
    {
        $this->useProvider();
        $project = $this->create();
        ScriptedSourceProvider::$steps = [AcquisitionStep::failed('EXPORT_FAILED', 'Export refusé.')];
        $source = $this->acquire($project);
        $this->assertSame(VideoSourceStatus::Failed, $source->status);
        $this->assertSame('EXPORT_FAILED', $source->error_code);
        $this->assertNull($source->media_id);

        // Échec temporaire : nouvel essai différé, la source reste en attente.
        $other = $this->create();
        ScriptedSourceProvider::$steps = [AcquisitionStep::failed('PROVIDER_UNAVAILABLE', 'Indisponible.', transient: true, retryAfterSeconds: 90)];
        $this->assertSame(VideoSourceStatus::WaitingForProvider, $this->acquire($other)->status);

        // Fournisseur sans accès (consentement, région) : action requise.
        $third = $this->create();
        ScriptedSourceProvider::$steps = [AcquisitionStep::unavailable('CONSENT_REQUIRED', 'Autorisation requise.')];
        $this->assertSame(VideoSourceStatus::Unavailable, $this->acquire($third)->status);
    }

    public function test_an_acquired_file_that_is_too_large_is_refused_and_deleted(): void
    {
        $this->useProvider();
        config(['video.sources.max_bytes' => 10]);
        $project = $this->create();
        $path = tempnam(sys_get_temp_dir(), 'src');
        file_put_contents($path, str_repeat('x', 64));
        ScriptedSourceProvider::$steps = [AcquisitionStep::acquired($path, 'video.mp4', 'video/mp4')];

        $source = $this->acquire($project);
        $this->assertSame(VideoSourceStatus::Failed, $source->status);
        $this->assertSame('SOURCE_TOO_LARGE', $source->error_code);
        $this->assertFileDoesNotExist($path, 'Fichier temporaire supprimé.');
        $this->assertSame(0, Media::query()->count());
    }

    public function test_a_processed_file_is_linked_and_the_analysis_resumes_by_itself(): void
    {
        $project = $this->create();
        $media = $this->video();
        $project->videoSource->forceFill(['status' => VideoSourceStatus::Resolving, 'acquisition_method' => AcquisitionMethod::External, 'media_id' => $media->id])->save();
        $project->forceFill(['status' => 'ready'])->save();

        $source = $this->acquire($project);
        $this->assertSame(VideoSourceStatus::Ready, $source->status);
        $this->assertSame($media->id, $project->fresh()->source_media_id);
        $this->assertNotNull($source->purge_at, 'Fichier acquis automatiquement : conservation limitée.');
        $this->assertSame(90_000, $source->duration_ms);
        Queue::assertPushed(AnalyzeClipSourceJob::class, 2);

        // Trop longue : refusée, jamais liée.
        config(['video.clipper.max_source_minutes' => 1]);
        $long = $this->create();
        $long->videoSource->forceFill(['status' => VideoSourceStatus::Resolving, 'acquisition_method' => AcquisitionMethod::External, 'media_id' => $this->video()->id])->save();
        $this->assertSame('SOURCE_TOO_LONG', $this->acquire($long)->error_code);
        $this->assertNull($long->fresh()->source_media_id);
    }

    public function test_the_authorized_import_finalizes_the_source_without_ever_being_purged(): void
    {
        $project = $this->create();
        $project->forceFill(['status' => 'ready'])->save();
        $media = $this->video();

        $this->api()->putJson("/api/v1/clipper/projects/{$project->id}/source", ['media_id' => $media->id])->assertOk()
            ->assertJsonPath('data.renderable', true)
            ->assertJsonPath('data.source_status.status', 'ready')
            ->assertJsonPath('data.source_status.method', 'import');
        $source = $project->videoSource()->first();
        $this->assertSame(AcquisitionMethod::Import, $source->acquisition_method);
        $this->assertNull($source->purge_at);

        // Fichier au-delà de la taille maximale : refusé.
        $other = $this->create();
        $other->forceFill(['status' => 'ready'])->save();
        config(['video.sources.max_bytes' => 1000]);
        $this->api()->putJson("/api/v1/clipper/projects/{$other->id}/source", ['media_id' => $this->video()->id])
            ->assertStatus(422)->assertJsonPath('code', 'SOURCE_TOO_LARGE');
    }

    public function test_acquisition_can_be_retried_without_duplicates(): void
    {
        $project = $this->create();
        $this->acquire($project); // unavailable
        Queue::fake();

        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/source/acquire")->assertStatus(202)
            ->assertJsonPath('data.source_status.status', 'pending');
        Queue::assertPushed(AcquireVideoSourceJob::class, 1);

        // Déjà en attente d'un fournisseur : aucun job supplémentaire.
        $project->videoSource->forceFill(['status' => VideoSourceStatus::WaitingForProvider])->save();
        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/source/acquire")->assertStatus(202);
        Queue::assertPushed(AcquireVideoSourceJob::class, 1);

        // Source prête : 409.
        $project->forceFill(['status' => 'ready'])->save();
        app(ClipperService::class)->linkSource($project, $this->video(), AcquisitionMethod::Import);
        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/source/acquire")->assertStatus(409)->assertJsonPath('code', 'SOURCE_ALREADY_READY');

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->fromSpa()->postJson("/api/v1/clipper/projects/{$project->id}/source/acquire")->assertNotFound();
    }

    public function test_expired_automatic_sources_are_purged_but_never_imports_or_files_in_use(): void
    {
        $acquired = $this->create();
        $acquired->forceFill(['status' => 'ready'])->save();
        $media = $this->video();
        app(ClipperService::class)->linkSource($acquired, $media, AcquisitionMethod::DataPortability, now()->subMinute());
        $acquired->refresh()->forceFill(['status' => 'ready'])->save();

        $imported = $this->create();
        $imported->forceFill(['status' => 'ready'])->save();
        $mine = $this->video();
        app(ClipperService::class)->linkSource($imported, $mine, AcquisitionMethod::Import);
        $imported->refresh()->forceFill(['status' => 'ready'])->save();
        $imported->videoSource->forceFill(['purge_at' => now()->subDay()])->save(); // même si une date traînait

        $busy = $this->create();
        $busy->forceFill(['status' => 'ready'])->save();
        app(ClipperService::class)->linkSource($busy, $this->video(), AcquisitionMethod::External, now()->subMinute());
        $busy->refresh()->forceFill(['status' => 'ready'])->save();
        $clip = new VideoClip(['title' => 'En cours', 'start_ms' => 0, 'end_ms' => 10_000, 'aspect_ratio' => '9:16']);
        $clip->video_project_id = $busy->id;
        $clip->forceFill(['source' => 'manual', 'status' => 'rendering'])->save();

        $this->artisan('clipper:expire-sources')->assertSuccessful();

        $this->assertSame(VideoSourceStatus::Expired, $acquired->videoSource()->first()->status);
        $this->assertNull($acquired->fresh()->source_media_id);
        $this->assertSoftDeleted('media', ['id' => $media->id]);
        $this->assertSame(VideoSourceStatus::Ready, $imported->videoSource()->first()->status);
        $this->assertNotSoftDeleted('media', ['id' => $mine->id]);
        $this->assertSame(VideoSourceStatus::Ready, $busy->videoSource()->first()->status, 'Source utilisée par un rendu : jamais purgée.');
        $this->api()->getJson("/api/v1/clipper/projects/{$acquired->id}")
            ->assertJsonPath('data.renderable', false)
            ->assertJsonPath('data.source_status.status', 'expired')
            ->assertJsonPath('data.source_status.error_code', 'SOURCE_EXPIRED');
    }
}

/** Double de test : fournisseur dont les étapes sont fixées par le test (jamais utilisé par le produit). */
class ScriptedSourceProvider implements VideoSourceProvider
{
    /** @var list<AcquisitionStep> */
    public static array $steps = [];

    /** @var list<string> */
    public static array $calls = [];

    public function name(): string
    {
        return 'scripted';
    }

    public function method(): AcquisitionMethod
    {
        return AcquisitionMethod::External;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supports(VideoSource $source): bool
    {
        return true;
    }

    public function begin(VideoSource $source): AcquisitionStep
    {
        self::$calls[] = 'begin';

        return array_shift(self::$steps) ?? AcquisitionStep::unavailable('NONE', 'Aucune étape prévue.');
    }

    public function poll(VideoSource $source): AcquisitionStep
    {
        self::$calls[] = 'poll';

        return array_shift(self::$steps) ?? AcquisitionStep::unavailable('NONE', 'Aucune étape prévue.');
    }
}
