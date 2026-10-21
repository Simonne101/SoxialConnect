<?php

namespace Tests\Feature\Clipper;

use App\Domain\Video\Clipper\ClipperService;
use App\Domain\Video\Enums\AcquisitionMethod;
use App\Domain\Video\Enums\ClipStatus;
use App\Domain\Video\Enums\SourceOwnership;
use App\Domain\Video\Enums\VideoSourceStatus;
use App\Domain\Video\Rendering\FfmpegBinary;
use App\Domain\Video\Rendering\MediaProbe;
use App\Domain\Video\Sources\AcquisitionStep;
use App\Domain\Video\Sources\VideoSourceProvider;
use App\Domain\Video\Sources\VideoSourceService;
use App\Jobs\AcquireVideoSourceJob;
use App\Jobs\AnalyzeClipSourceJob;
use App\Jobs\RenderClipJob;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Source accessible sans import manuel (ADR-053) : réutilisation d'un fichier de la même vidéo déjà
 * fourni par l'utilisateur, acquisition réussie par un fournisseur (double de test ReuseScriptedProvider
 * avec un VRAI MP4 produit par FFmpeg), source inaccessible ou non autorisée expliquée précisément,
 * purge sûre d'un fichier partagé, puis rendu final réel (FFmpeg) depuis une source réutilisée.
 */
class VideoSourceReuseTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    private User $user;

    private string $temp;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('media');
        Http::preventStrayRequests();
        Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 'Conférence IA', 'author_name' => 'Chaîne Test', 'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'])]);
        ReuseScriptedProvider::$steps = [];
        ReuseScriptedProvider::$calls = [];
        $this->temp = storage_path('framework/testing/clipper-reuse');
        config(['video.temp_path' => $this->temp, 'media.process_async' => false]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temp ?? '');
        parent::tearDown();
    }

    private function api(?User $user = null): static
    {
        return $this->actingAs($user ?? $this->user)->fromSpa();
    }

    /** @param array<string, mixed> $extra */
    private function create(?User $user = null, array $extra = [], string $url = self::URL): VideoProject
    {
        $id = $this->api($user)->postJson('/api/v1/clipper/projects', ['youtube_url' => $url, 'aspect_ratio' => '9:16', ...$extra])->assertCreated()->json('data.id');

        return VideoProject::findOrFail($id);
    }

    private function acquire(VideoProject $project): VideoSource
    {
        app(VideoSourceService::class)->acquire((string) $project->videoSource()->value('id'));

        return $project->videoSource()->firstOrFail();
    }

    private function video(?User $user = null, array $attributes = []): Media
    {
        return Media::factory()->for($user ?? $this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'size_bytes' => 4096, 'duration_ms' => 90_000, ...$attributes]);
    }

    private function requireFfmpeg(): void
    {
        $binaries = app(FfmpegBinary::class);
        if (! $binaries->isAvailable() || ! $binaries->isProbeAvailable()) {
            $this->markTestSkipped('FFmpeg/FFprobe introuvable : test réel ignoré.');
        }
    }

    /** Vraie vidéo de test 640×360 avec son (FFmpeg, aucun téléchargement). */
    private function mp4(string $target, int $seconds = 8): void
    {
        File::ensureDirectoryExists(dirname($target));
        (new Process([config('video.binaries.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', "testsrc2=s=640x360:r=25:d={$seconds}", '-f', 'lavfi', '-i', "sine=f=440:d={$seconds}",
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $target]))->setTimeout(300)->mustRun();
    }

    public function test_a_file_already_provided_for_the_same_video_is_reused_at_creation_without_copy_or_import(): void
    {
        $media = $this->video();
        $first = $this->create(extra: ['media_id' => $media->id]);
        $this->assertSame(AcquisitionMethod::Import, $first->videoSource->acquisition_method);
        Queue::fake();
        $before = Media::query()->count();

        // Même vidéo, lien seul (autre forme du lien) : la source est prête immédiatement.
        $id = $this->api()->postJson('/api/v1/clipper/projects', ['youtube_url' => 'https://youtu.be/dQw4w9WgXcQ', 'aspect_ratio' => '1:1'])->assertCreated()
            ->assertJsonPath('data.renderable', true)
            ->assertJsonPath('data.source_status.status', 'ready')
            ->assertJsonPath('data.source_status.method', 'library')
            ->json('data.id');

        $second = VideoProject::findOrFail($id);
        $this->assertSame($media->id, $second->source_media_id);
        $this->assertSame($before, Media::query()->count(), 'Aucune copie ni nouvel import.');
        $this->assertNull($second->videoSource->purge_at, 'Fichier importé par l\'utilisateur : jamais purgé, même réutilisé.');
        Queue::assertNotPushed(AcquireVideoSourceJob::class);
        Queue::assertPushed(AnalyzeClipSourceJob::class, fn ($job) => $job->projectId === $second->id);
    }

    public function test_a_pending_acquisition_reuses_a_file_provided_later_without_any_network_request(): void
    {
        $pending = $this->create();
        $pending->forceFill(['status' => 'ready'])->save();
        $other = $this->create();
        $other->forceFill(['status' => 'ready'])->save();
        $media = $this->video();
        app(ClipperService::class)->attachSource($other, $media->id);
        $sent = count(Http::recorded());

        $source = $this->acquire($pending);
        $this->assertSame(VideoSourceStatus::Ready, $source->status);
        $this->assertSame(AcquisitionMethod::Library, $source->acquisition_method);
        $this->assertSame($media->id, $pending->fresh()->source_media_id);
        $this->assertSame($sent, count(Http::recorded()), 'Réutilisation locale : aucune requête réseau (ni vérification YouTube).');
        Queue::assertPushed(AnalyzeClipSourceJob::class, fn ($job) => $job->projectId === $pending->id);
    }

    public function test_reuse_is_isolated_per_user_per_video_and_requires_a_usable_file(): void
    {
        $this->create(extra: ['media_id' => $this->video()->id]);

        // Autre utilisateur, même vidéo : jamais le fichier d'un autre compte.
        $stranger = User::factory()->create();
        $theirs = $this->create($stranger);
        $this->assertNull($theirs->source_media_id);
        $source = $this->acquire($theirs);
        $this->assertSame(VideoSourceStatus::Unavailable, $source->status);
        $this->assertNull($source->media_id);

        // Même utilisateur, autre vidéo : aucun rapprochement deviné.
        $this->assertNull($this->create(url: 'https://www.youtube.com/watch?v=jNQXAC9IVRw')->source_media_id);

        // Fichier supprimé ou refusé par l'antivirus : plus réutilisable.
        $user = User::factory()->create();
        $deleted = $this->video($user);
        $this->create($user, ['media_id' => $deleted->id]);
        $deleted->delete();
        $this->assertNull($this->create($user)->source_media_id);
        $infected = $this->video($user, ['scan_status' => 'infected']);
        VideoSource::query()->where('user_id', $user->id)->update(['media_id' => $infected->id]);
        $this->assertNull($this->create($user)->source_media_id);
    }

    public function test_reuse_can_be_disabled_and_respects_current_source_limits(): void
    {
        $this->create(extra: ['media_id' => $this->video()->id]);

        config(['video.sources.reuse_library' => false]);
        $this->assertNull($this->create()->source_media_id);

        config(['video.sources.reuse_library' => true, 'video.clipper.max_source_minutes' => 1]);
        $this->assertNull($this->create()->source_media_id, 'Durée au-delà de la limite actuelle : non réutilisé.');
    }

    public function test_an_inaccessible_source_is_explained_precisely_with_the_authorized_import_as_fallback(): void
    {
        $project = $this->create();
        $source = $this->acquire($project);

        $this->assertSame(VideoSourceStatus::Unavailable, $source->status);
        $this->assertSame('VIDEO_DOWNLOAD_DISABLED', $source->error_code);
        $this->assertStringContainsString('n\'est pas accessible automatiquement', $source->error_message);
        $this->assertStringContainsString('Importez un fichier dont vous détenez les droits', $source->error_message);
        $this->assertStringNotContainsString('Comptes sociaux', $source->error_message, 'Export Google désactivé : jamais mentionné.');
        $this->api()->getJson("/api/v1/clipper/projects/{$project->id}")
            ->assertJsonPath('data.renderable', false)
            ->assertJsonPath('data.source_status.error_code', 'VIDEO_DOWNLOAD_DISABLED');

        // Solution de remplacement : import d'un fichier autorisé.
        $project->forceFill(['status' => 'ready'])->save();
        $this->api()->putJson("/api/v1/clipper/projects/{$project->id}/source", ['media_id' => $this->video()->id])->assertOk()
            ->assertJsonPath('data.renderable', true)
            ->assertJsonPath('data.source_status.method', 'import');
    }

    public function test_a_stale_unavailable_diagnosis_is_never_shown_and_is_retried_once_a_provider_exists(): void
    {
        // Diagnostic enregistré avant l'activation d'une voie d'acquisition (texte d'une version antérieure).
        $project = $this->create();
        $project->videoSource->forceFill(['status' => VideoSourceStatus::Unavailable, 'error_code' => 'PROVIDER_NOT_CONFIGURED',
            'error_message' => 'Aucune acquisition automatique de la vidéo n\'est activée sur ce serveur.'])->save();
        $cancelled = $this->create(url: 'https://www.youtube.com/watch?v=jNQXAC9IVRw');
        $cancelled->videoSource->forceFill(['status' => VideoSourceStatus::Unavailable, 'error_code' => 'PROVIDER_NOT_CONFIGURED'])->save();
        $cancelled->forceFill(['status' => 'cancelled'])->save();

        $message = $this->api()->getJson("/api/v1/clipper/projects/{$project->id}")->json('data.source_status.error_message');
        $this->assertStringNotContainsString('Aucune acquisition automatique', $message, 'Message recalculé selon la configuration actuelle.');
        $this->assertStringContainsString('Importez un fichier dont vous détenez les droits', $message);

        // Aucune voie configurée : rien n'est relancé.
        Queue::fake();
        $this->artisan('clipper:retry-unavailable-sources')->assertSuccessful();
        Queue::assertNothingPushed();

        // Une voie est désormais active : la recherche reprend (jamais pour un projet annulé).
        config(['video.sources.providers' => [ReuseScriptedProvider::class]]);
        $this->artisan('clipper:retry-unavailable-sources')->assertSuccessful();
        $this->assertSame(VideoSourceStatus::Pending, $project->videoSource()->first()->status);
        $this->assertSame(VideoSourceStatus::Unavailable, $cancelled->videoSource()->first()->status);
        Queue::assertPushed(AcquireVideoSourceJob::class, 1);
    }

    public function test_a_video_without_authorization_is_never_acquired_and_the_reason_is_given(): void
    {
        // Fournisseur officiel actif mais réservé au propriétaire : vidéo d'un tiers.
        config(['video.sources.providers' => [OwnerOnlyScriptedProvider::class]]);
        $project = $this->create();
        $project->videoSource->forceFill(['ownership_status' => SourceOwnership::NotOwned, 'ownership_checked_at' => now()])->save();

        $source = $this->acquire($project);
        $this->assertSame(VideoSourceStatus::Unavailable, $source->status);
        $this->assertSame('NO_PROVIDER_FOR_SOURCE', $source->error_code);
        $this->assertStringContainsString('ne s\'applique qu\'aux vidéos de vos propres chaînes', $source->error_message);
        $this->assertStringContainsString('Importez un fichier dont vous détenez les droits', $source->error_message);
        $this->assertSame([], ReuseScriptedProvider::$calls, 'Fournisseur jamais sollicité sans autorisation.');

        // Propriété non vérifiable (aucune chaîne connectée) : erreur d'acquisition et import autorisé ; jamais
        // une demande de connecter la chaîne qui a publié la vidéo (ADR-058), l'export Google reste facultatif.
        $unverified = $this->create();
        $message = (string) $this->acquire($unverified)->error_message;
        $this->assertStringContainsString('Importez un fichier dont vous détenez les droits', $message);
        $this->assertStringContainsString('S\'il s\'agit d\'une vidéo de votre propre chaîne', $message);
        $this->assertStringNotContainsString('qui l\'a publiée', $message);

        // Consentement refusé par le fournisseur : action requise, aucun fichier.
        config(['video.sources.providers' => [ReuseScriptedProvider::class]]);
        $third = $this->create();
        ReuseScriptedProvider::$steps = [AcquisitionStep::unavailable('CONSENT_REQUIRED', 'Autorisation requise.')];
        $source = $this->acquire($third);
        $this->assertSame(['CONSENT_REQUIRED', null], [$source->error_code, $source->media_id]);
    }

    public function test_a_successful_acquisition_imports_a_real_file_then_links_it_and_deletes_the_temporary_copy(): void
    {
        $this->requireFfmpeg();
        config(['video.sources.providers' => [ReuseScriptedProvider::class]]);
        $project = $this->create();
        $project->forceFill(['status' => 'ready'])->save();
        $path = $this->temp.'/acquired/source.mp4';
        $this->mp4($path);
        ReuseScriptedProvider::$steps = [AcquisitionStep::acquired($path, 'conference.mp4', 'video/mp4')];

        $source = $this->acquire($project);
        $this->assertFileDoesNotExist($path, 'Fichier temporaire du fournisseur supprimé.');
        $this->assertSame(VideoSourceStatus::Resolving, $source->status);
        $media = Media::findOrFail($source->media_id);
        $this->assertSame([$this->user->id, 'video/mp4'], [$media->user_id, $media->mime_type]);

        $source = $this->acquire($project); // AcquireVideoSourceJob différé : finalisation
        $this->assertSame(VideoSourceStatus::Ready, $source->status, (string) $source->error_message);
        $this->assertSame(AcquisitionMethod::External, $source->acquisition_method);
        $this->assertNotNull($source->purge_at);
        $this->assertSame($media->id, $project->fresh()->source_media_id);
    }

    public function test_a_shared_file_is_purged_only_once_no_project_uses_it_any_more(): void
    {
        $media = $this->video();
        $acquired = $this->create();
        $acquired->forceFill(['status' => 'ready'])->save();
        app(ClipperService::class)->linkSource($acquired, $media, AcquisitionMethod::DataPortability, now()->subMinute());
        $acquired->refresh()->forceFill(['status' => 'ready'])->save();

        // Réutilisé par un second projet dont la conservation court encore.
        $reused = $this->create();
        $this->assertSame($media->id, $reused->source_media_id);
        $reused->videoSource->forceFill(['purge_at' => now()->addDay()])->save();
        $reused->forceFill(['status' => 'ready'])->save();

        $this->artisan('clipper:expire-sources')->assertSuccessful();
        $this->assertSame(VideoSourceStatus::Expired, $acquired->videoSource()->first()->status);
        $this->assertNotSoftDeleted('media', ['id' => $media->id]);
        $this->assertSame($media->id, $reused->fresh()->source_media_id);

        $this->travel(2)->days();
        $this->artisan('clipper:expire-sources')->assertSuccessful();
        $this->assertSame(VideoSourceStatus::Expired, $reused->videoSource()->first()->status);
        $this->assertSoftDeleted('media', ['id' => $media->id]);
    }

    public function test_a_cancelled_project_never_reuses_nor_acquires(): void
    {
        $project = $this->create();
        $this->create(extra: ['media_id' => $this->video()->id]);
        $project->forceFill(['status' => 'cancelled'])->save();

        $source = $this->acquire($project);
        $this->assertSame(VideoSourceStatus::Pending, $source->status);
        $this->assertNull($project->fresh()->source_media_id);
    }

    public function test_a_clip_is_really_rendered_from_a_reused_source(): void
    {
        $this->requireFfmpeg();
        $media = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'width' => 640, 'height' => 360, 'duration_ms' => 8_000]);
        $media->forceFill(['path' => "users/{$this->user->id}/media/{$media->id}/original.mp4"])->save();
        $this->mp4(Storage::disk('media')->path($media->path));
        $this->create(extra: ['media_id' => $media->id]);

        $project = $this->create();
        $this->assertSame($media->id, $project->source_media_id);
        $project->forceFill(['status' => 'ready', 'duration_ms' => 8_000])->save(); // analyse (file d'attente simulée) terminée
        $clipId = $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/clips", ['start_ms' => 1_000, 'end_ms' => 5_000, 'aspect_ratio' => '9:16'])
            ->assertStatus(202)->json('data.id');
        $this->api(User::factory()->create())->getJson("/api/v1/clipper/projects/{$project->id}")->assertNotFound();
        app()->call([new RenderClipJob($clipId), 'handle']);

        $clip = $project->clips()->findOrFail($clipId);
        $this->assertSame(ClipStatus::Rendered, $clip->status, (string) $clip->error_message);
        $output = Media::findOrFail($clip->output_media_id);
        $probe = app(MediaProbe::class)->probe(Storage::disk('media')->path($output->path));
        $this->assertSame([1080, 1920], [$probe->width, $probe->height]);
        $this->assertEqualsWithDelta(4_000, $probe->durationMs, 150);
        Storage::disk('media')->assertExists($media->path); // la source partagée n'est jamais touchée
        $this->assertSame([], File::isDirectory($this->temp) ? File::directories($this->temp) : [], 'Dossier de travail supprimé.');
    }
}

/** Double de test : fournisseur dont les étapes sont fixées par le test (jamais utilisé par le produit). */
class ReuseScriptedProvider implements VideoSourceProvider
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

/** Double de test : fournisseur officiel réservé au propriétaire de la vidéo. */
class OwnerOnlyScriptedProvider extends ReuseScriptedProvider
{
    public function supports(VideoSource $source): bool
    {
        return $source->ownership_status === SourceOwnership::Owned;
    }
}
