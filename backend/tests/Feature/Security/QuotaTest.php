<?php

namespace Tests\Feature\Security;

use App\Domain\Quota\ClippingQuota;
use App\Domain\Quota\DiskGuard;
use App\Domain\Quota\DiskSpaceInsufficient;
use App\Domain\Quota\QuotaExceeded;
use App\Domain\Quota\StorageQuota;
use App\Domain\Video\Clipper\ClipAnalysisRunner;
use App\Domain\Video\Clipper\ClipRenderRunner;
use App\Domain\Video\Enums\AcquisitionMethod;
use App\Domain\Video\Enums\VideoSourceStatus;
use App\Domain\Video\Sources\AcquisitionStep;
use App\Domain\Video\Sources\VideoSourceProvider;
use App\Domain\Video\Sources\VideoSourceService;
use App\Jobs\AcquireVideoSourceJob;
use App\Jobs\AnalyzeClipSourceJob;
use App\Jobs\RenderClipJob;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoClip;
use App\Models\VideoProject;
use App\Models\VideoSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Media\Concerns\MediaFixtures;
use Tests\TestCase;

/**
 * Quotas de sécurité (étape 8, lot 4 — ADR-071) : stockage par utilisateur, clipping (projets actifs et sur
 * 24 h), garde d'espace disque. Vérifie aussi qu'aucun traitement coûteux (fournisseur d'acquisition, FFmpeg,
 * analyse) ne démarre quand une limite est atteinte, et que les réservations sont libérées.
 */
class QuotaTest extends TestCase
{
    use MediaFixtures, RefreshDatabase;

    private const MB = 1024 * 1024;

    private const URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        Queue::fake();
        Http::preventStrayRequests();
        QuotaProbeProvider::$calls = [];
        config(['video.sources.reuse_library' => false]);
        $this->user = User::factory()->create();
    }

    private function freeDisk(int $bytes): void
    {
        $this->app->instance(DiskGuard::class, new DiskGuard(fn () => (float) $bytes));
    }

    private function media(User $user, int $bytes, array $attributes = []): Media
    {
        return Media::factory()->for($user)->create(['size_bytes' => $bytes, ...$attributes]);
    }

    private function createProject(): TestResponse
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 'Vidéo', 'author_name' => 'Chaîne'])]);

        return $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/clipper/projects', ['youtube_url' => self::URL, 'aspect_ratio' => '9:16']);
    }

    private function clippingProject(string $status, array $attributes = []): VideoProject
    {
        $project = VideoProject::factory()->for($this->user)->clipping()->create($attributes);
        $project->forceFill(['status' => $status])->save();

        return $project;
    }

    // --- Stockage --------------------------------------------------------------------------------------

    public function test_storage_usage_counts_originals_derivatives_quarantine_generated_and_trashed_media(): void
    {
        $this->media($this->user, 5 * self::MB);
        $this->media($this->user, 2 * self::MB, ['scan_status' => 'pending', 'status' => 'processing']); // quarantaine / traitement
        $this->media($this->user, 3 * self::MB, ['derivatives' => ['thumbnail' => ['path' => 'x', 'mime_type' => 'image/webp', 'size_bytes' => self::MB]]]); // généré + vignette
        $this->media($this->user, 4 * self::MB)->delete(); // suppression logique : fichier pas encore purgé
        $this->media(User::factory()->create(), 50 * self::MB); // autre utilisateur

        $this->assertSame(15 * self::MB, app(StorageQuota::class)->storedBytes((string) $this->user->id));
    }

    public function test_an_upload_beyond_the_quota_is_refused_with_413_and_nothing_is_stored(): void
    {
        config(['quotas.storage.max_mb' => 1]);
        $this->media($this->user, self::MB - 10)->delete(); // supprimé logiquement : toujours compté

        $this->storeMedia($this->user, $this->png())->assertStatus(413)->assertJsonPath('code', 'STORAGE_QUOTA_EXCEEDED');

        $this->assertSame([], Storage::disk('media')->allFiles());
        $this->assertSame(0, Media::query()->count());
        $this->assertSame(0, app(StorageQuota::class)->reservedBytes((string) $this->user->id), 'Aucune réservation laissée.');
    }

    public function test_an_upload_within_the_quota_still_works_and_releases_its_reservation(): void
    {
        config(['quotas.storage.max_mb' => 1]);

        $this->storeMedia($this->user, $this->png())->assertCreated();

        $this->assertSame(1, Media::query()->count());
        $this->assertSame(0, app(StorageQuota::class)->reservedBytes((string) $this->user->id));
    }

    public function test_two_concurrent_requests_near_the_limit_cannot_both_pass(): void
    {
        config(['quotas.storage.max_mb' => 10]);
        $quota = app(StorageQuota::class);

        // Première demande (import ou rendu en cours) : 6 Mo réservés sous verrou.
        $first = $quota->reserve($this->user, 6 * self::MB, 'upload');
        // Seconde demande simultanée de 6 Mo : refusée, l'espace réservé par la première est compté.
        try {
            $quota->reserve($this->user, 6 * self::MB, 'upload');
            $this->fail('La seconde réservation aurait dépassé le quota.');
        } catch (QuotaExceeded $e) {
            $this->assertSame([413, 'STORAGE_QUOTA_EXCEEDED'], [$e->status, $e->errorCode]);
        }
        // Une seconde demande qui tient dans le reste passe.
        $second = $quota->reserve($this->user, 4 * self::MB, 'upload');
        $this->assertSame(10 * self::MB, $quota->usedBytes((string) $this->user->id));

        $quota->release($this->user, $first);
        $quota->release($this->user, $second);
        $this->assertSame(0, $quota->reservedBytes((string) $this->user->id));
    }

    public function test_a_forgotten_reservation_expires_by_itself(): void
    {
        config(['quotas.storage.max_mb' => 10, 'quotas.storage.reservation_ttl_minutes' => 30]);
        app(StorageQuota::class)->reserve($this->user, 8 * self::MB, 'render'); // worker tué : jamais libérée

        $this->travel(31)->minutes();

        $this->assertSame(0, app(StorageQuota::class)->reservedBytes((string) $this->user->id));
    }

    // --- Disque ----------------------------------------------------------------------------------------

    public function test_an_upload_is_refused_with_507_when_the_disk_is_almost_full_and_the_quota_reservation_is_released(): void
    {
        config(['quotas.disk.min_free_mb' => 2048]);
        $this->freeDisk(2048 * self::MB); // le fichier ferait passer sous le seuil minimal

        $this->storeMedia($this->user, $this->png())->assertStatus(507)->assertJsonPath('code', 'DISK_SPACE_LOW')->assertHeader('Retry-After', '900');

        $this->assertSame([], Storage::disk('media')->allFiles());
        $this->assertSame(0, app(StorageQuota::class)->reservedBytes((string) $this->user->id));
    }

    public function test_concurrent_disk_reservations_cannot_exceed_the_free_space(): void
    {
        config(['quotas.disk.min_free_mb' => 100, 'quotas.disk.margin_percent' => 0]);
        $this->freeDisk(1100 * self::MB); // 1 000 Mo utilisables
        $guard = app(DiskGuard::class);

        $first = $guard->reserve(700 * self::MB, 'acquisition');
        try {
            $guard->reserve(400 * self::MB, 'render');
            $this->fail('La seconde opération aurait consommé l\'espace déjà réservé.');
        } catch (DiskSpaceInsufficient) {
        }
        $guard->release($first);
        $this->assertIsString($guard->reserve(400 * self::MB, 'render'), 'Espace rendu par la première opération.');
    }

    // --- Clipping : projets actifs et créations sur 24 h ------------------------------------------------

    public function test_the_active_projects_limit_refuses_a_new_project_before_any_external_call_or_job(): void
    {
        config(['quotas.clipping.max_active' => 2]);
        $this->clippingProject('analyzing');
        $this->clippingProject('pending');

        $this->createProject()->assertStatus(429)->assertJsonPath('code', 'CLIPPING_ACTIVE_LIMIT')->assertHeader('Retry-After');

        $this->assertSame(2, VideoProject::query()->count());
        Queue::assertNotPushed(AcquireVideoSourceJob::class);
        Queue::assertNotPushed(AnalyzeClipSourceJob::class);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'oembed'));
    }

    public function test_finished_failed_cancelled_or_stalled_projects_free_their_slot(): void
    {
        config(['quotas.clipping.max_active' => 1, 'quotas.clipping.stale_after_minutes' => 60]);
        $this->clippingProject('ready');
        $this->clippingProject('failed');
        $this->clippingProject('cancelled');
        $stalled = $this->clippingProject('transcribing');
        VideoProject::query()->whereKey($stalled->id)->update(['updated_at' => now()->subHours(2)]); // worker tué

        $this->createProject()->assertCreated();
        $this->assertSame(1, app(ClippingQuota::class)->activeCount((string) $this->user->id));
    }

    public function test_a_ready_project_rendering_a_clip_counts_as_active(): void
    {
        config(['quotas.clipping.max_active' => 1]);
        $project = $this->clippingProject('ready');
        $clip = new VideoClip(['title' => 'Clip', 'start_ms' => 0, 'end_ms' => 10_000, 'aspect_ratio' => '9:16']);
        $clip->video_project_id = $project->id;
        $clip->forceFill(['source' => 'manual', 'status' => 'rendering'])->save();

        $this->createProject()->assertStatus(429)->assertJsonPath('code', 'CLIPPING_ACTIVE_LIMIT');
    }

    public function test_the_daily_limit_counts_deleted_projects_and_reopens_after_24_hours(): void
    {
        config(['quotas.clipping.max_per_day' => 2]);
        $this->clippingProject('ready');
        $this->clippingProject('ready')->delete(); // une suppression ne rend pas le quota

        $this->createProject()->assertStatus(429)->assertJsonPath('code', 'CLIPPING_DAILY_LIMIT');

        $this->travel(25)->hours();
        $this->createProject()->assertCreated();
    }

    public function test_concurrent_creations_near_the_limit_are_serialized(): void
    {
        config(['quotas.clipping.max_active' => 1]);
        // Une création concurrente détient le verrou de l'utilisateur : la demande attend puis voit le projet créé.
        $quota = app(ClippingQuota::class);
        $quota->forNewProject($this->user, fn () => $this->clippingProject('pending'));

        try {
            $quota->forNewProject($this->user, fn () => $this->clippingProject('pending'));
            $this->fail('La seconde création aurait dépassé la limite.');
        } catch (QuotaExceeded $e) {
            $this->assertSame('CLIPPING_ACTIVE_LIMIT', $e->errorCode);
        }
        $this->assertSame(1, VideoProject::query()->count());
        $this->assertFalse(Cache::lock('quota-lock:clipping:'.$this->user->id, 1)->get() === false, 'Verrou rendu après chaque demande.');
    }

    public function test_relaunching_work_on_an_inactive_project_needs_a_free_slot(): void
    {
        config(['quotas.clipping.max_active' => 1]);
        $source = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        $ready = $this->clippingProject('ready', ['source_media_id' => $source->id]);
        $ready->forceFill(['duration_ms' => 600_000])->save();
        $this->clippingProject('analyzing'); // la seule place est prise

        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/clipper/projects/{$ready->id}/clips", ['start_ms' => 0, 'end_ms' => 20_000, 'aspect_ratio' => '9:16'])
            ->assertStatus(429)->assertJsonPath('code', 'CLIPPING_ACTIVE_LIMIT');
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/clipper/projects/{$ready->id}/reanalyze")
            ->assertStatus(429)->assertJsonPath('code', 'CLIPPING_ACTIVE_LIMIT');
        Queue::assertNotPushed(RenderClipJob::class);
        Queue::assertNotPushed(AnalyzeClipSourceJob::class);
    }

    // --- Aucun traitement coûteux au-delà des limites ----------------------------------------------------

    public function test_no_acquisition_provider_is_called_when_the_storage_quota_is_exhausted(): void
    {
        config(['video.sources.providers' => [QuotaProbeProvider::class], 'quotas.storage.max_mb' => 100, 'quotas.storage.acquisition_min_mb' => 50]);
        $id = $this->createProject()->assertCreated()->json('data.id');
        $this->media($this->user, 60 * self::MB); // reste 40 Mo < 50 Mo

        app(VideoSourceService::class)->acquire((string) VideoProject::findOrFail($id)->videoSource()->value('id'));

        $source = VideoSource::query()->where('video_project_id', $id)->sole();
        $this->assertSame([VideoSourceStatus::Failed, 'STORAGE_QUOTA_EXCEEDED'], [$source->status, $source->error_code]);
        $this->assertSame([], QuotaProbeProvider::$calls, 'Aucun téléchargement lancé.');
    }

    public function test_no_acquisition_starts_when_the_disk_is_almost_full_and_downloads_are_capped_by_the_remaining_quota(): void
    {
        config(['video.sources.providers' => [QuotaProbeProvider::class], 'quotas.storage.max_mb' => 500]);
        $id = $this->createProject()->assertCreated()->json('data.id');
        $sourceId = (string) VideoProject::findOrFail($id)->videoSource()->value('id');

        $this->freeDisk(100 * self::MB);
        app(VideoSourceService::class)->acquire($sourceId);
        $this->assertSame([VideoSourceStatus::Failed, 'DISK_SPACE_LOW'], [VideoSource::find($sourceId)->status, VideoSource::find($sourceId)->error_code]);
        $this->assertSame([], QuotaProbeProvider::$calls);

        // Disque suffisant : le fournisseur est appelé avec une taille maximale bornée par le quota restant.
        $this->freeDisk(500_000 * self::MB);
        $this->media($this->user, 400 * self::MB);
        VideoSource::query()->whereKey($sourceId)->update(['status' => 'pending', 'error_code' => null]);
        app(VideoSourceService::class)->acquire($sourceId);
        $this->assertSame(['begin'], QuotaProbeProvider::$calls);
        $this->assertSame(100 * self::MB, QuotaProbeProvider::$maxBytes, 'Téléchargement plafonné au quota restant (100 Mo).');
    }

    public function test_a_clip_render_does_not_start_when_the_quota_or_the_disk_is_exhausted_and_frees_its_slot(): void
    {
        $source = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        $project = $this->clippingProject('ready', ['source_media_id' => $source->id]);
        $project->forceFill(['duration_ms' => 600_000])->save();
        $clipId = $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/clipper/projects/{$project->id}/clips", ['start_ms' => 0, 'end_ms' => 20_000, 'aspect_ratio' => '9:16'])
            ->assertStatus(202)->json('data.id');
        $this->assertSame(1, app(ClippingQuota::class)->activeCount((string) $this->user->id), 'Rendu en attente : projet actif.');

        config(['quotas.storage.max_mb' => 1]); // quota épuisé avant le démarrage du worker
        $this->media($this->user, 2 * self::MB);
        app(ClipRenderRunner::class)->run($clipId);

        $clip = VideoClip::findOrFail($clipId);
        $this->assertSame(['failed', 'STORAGE_QUOTA_EXCEEDED'], [$clip->status->value, $clip->error_code]);
        $this->assertNull($clip->output_media_id, 'Aucun rendu produit.');
        $this->assertDirectoryDoesNotExist(rtrim((string) config('video.temp_path'), '/\\').DIRECTORY_SEPARATOR.$clipId, 'Aucun dossier de travail : FFmpeg n\'a pas démarré.');
        $this->assertSame(0, app(ClippingQuota::class)->activeCount((string) $this->user->id), 'Échec : place de projet actif libérée.');
        $this->assertSame(0, app(StorageQuota::class)->reservedBytes((string) $this->user->id));
    }

    public function test_an_analysis_does_not_start_when_the_disk_is_almost_full(): void
    {
        $source = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        $project = $this->clippingProject('pending', ['source_media_id' => $source->id]);
        $this->freeDisk(100 * self::MB);

        app(ClipAnalysisRunner::class)->run($project->id);

        $project->refresh();
        $this->assertSame(['failed', 'DISK_SPACE_LOW'], [$project->status->value, $project->settings['analysis_error_code'] ?? null]);
        $this->assertSame(0, app(DiskGuard::class)->reservedBytes(), 'Réservation libérée.');
    }
}

/** Double de test : fournisseur d'acquisition qui enregistre ses appels et la taille maximale autorisée. */
class QuotaProbeProvider implements VideoSourceProvider
{
    /** @var list<string> */
    public static array $calls = [];

    public static ?int $maxBytes = null;

    public function name(): string
    {
        return 'quota-probe';
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
        self::$maxBytes = VideoSourceService::maxAcquiredBytes($source);

        return AcquisitionStep::failed('PROBE', 'Sonde de test : aucun téléchargement.');
    }

    public function poll(VideoSource $source): AcquisitionStep
    {
        self::$calls[] = 'poll';

        return AcquisitionStep::failed('PROBE', 'Sonde de test.');
    }
}
