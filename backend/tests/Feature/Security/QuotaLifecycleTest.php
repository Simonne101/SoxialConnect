<?php

namespace Tests\Feature\Security;

use App\Domain\Media\Storage\MediaStorage;
use App\Domain\Quota\ClippingQuota;
use App\Domain\Quota\DiskGuard;
use App\Domain\Quota\StorageQuota;
use App\Domain\Video\Clipper\ClipRenderRunner;
use App\Domain\Video\Sources\DataPortability\DataPortabilityArchiveExtractor;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoClip;
use App\Models\VideoProject;
use App\Models\VideoSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cycle de vie des quotas (étape 8, lot 4 — ADR-071) : libération après annulation et après un échec survenu
 * APRÈS la réservation, expiration des réservations disque, extraction d'archive refusée sans écriture, espace
 * rendu par la purge physique (et seulement par elle).
 */
class QuotaLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const MB = 1024 * 1024;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        Queue::fake();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
    }

    private function readyProject(): VideoProject
    {
        $source = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        $project = VideoProject::factory()->for($this->user)->clipping()->create(['source_media_id' => $source->id]);
        $project->forceFill(['status' => 'ready', 'duration_ms' => 600_000])->save();

        return $project;
    }

    public function test_cancelling_an_active_project_frees_its_slot_immediately(): void
    {
        config(['quotas.clipping.max_active' => 1]);
        $project = VideoProject::factory()->for($this->user)->clipping()->create();
        $project->forceFill(['status' => 'analyzing'])->save();
        $this->assertSame(1, app(ClippingQuota::class)->activeCount((string) $this->user->id));

        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/clipper/projects/{$project->id}/cancel")->assertSuccessful();

        $this->assertSame(0, app(ClippingQuota::class)->activeCount((string) $this->user->id), 'Annulation : place libérée sans compteur à corriger.');
    }

    public function test_a_render_that_fails_after_reserving_releases_quota_and_disk(): void
    {
        $project = $this->readyProject(); // fichier source absent du stockage : le rendu échouera après réservation
        $clipId = $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/clipper/projects/{$project->id}/clips", ['start_ms' => 0, 'end_ms' => 20_000])
            ->assertStatus(202)->json('data.id');

        app(ClipRenderRunner::class)->run($clipId);

        $clip = VideoClip::findOrFail($clipId);
        $this->assertSame('failed', $clip->status->value);
        $this->assertNotContains($clip->error_code, ['STORAGE_QUOTA_EXCEEDED', 'DISK_SPACE_LOW'], 'Échec du rendu lui-même, après réservation.');
        $this->assertSame(0, app(StorageQuota::class)->reservedBytes((string) $this->user->id), 'Quota libéré dans le finally.');
        $this->assertSame(0, app(DiskGuard::class)->reservedBytes(), 'Disque libéré dans le finally.');
    }

    public function test_disk_reservations_expire_by_themselves(): void
    {
        $guard = new DiskGuard(fn () => (float) (500_000 * self::MB));
        $guard->reserve(100 * self::MB, 'render'); // worker tué : jamais libérée
        $this->assertGreaterThan(0, $guard->reservedBytes());

        $this->travel(241)->minutes();

        $this->assertSame(0, $guard->reservedBytes());
    }

    public function test_an_archive_is_not_downloaded_when_the_disk_is_too_full(): void
    {
        $project = VideoProject::factory()->for($this->user)->clipping()->create();
        $source = new VideoSource;
        $source->forceFill(['user_id' => $this->user->id, 'video_project_id' => $project->id, 'provider' => 'youtube', 'external_reference' => 'dQw4w9WgXcQ',
            'source_type' => 'youtube_video', 'status' => 'waiting_for_provider'])->save();
        Http::fake(['storage.googleapis.com/*' => Http::response(str_repeat('x', 1000), 200, ['Content-Type' => 'application/zip', 'Content-Length' => (string) (900 * self::MB)])]);
        $this->app->instance(DiskGuard::class, new DiskGuard(fn () => (float) (2500 * self::MB))); // 900 Mo + marge : sous le seuil de 2 Go

        $step = app(DataPortabilityArchiveExtractor::class)->extract($source, ['https://storage.googleapis.com/dataportability-archive/part-1.zip?X-Goog-Signature=abc']);

        $this->assertSame('DISK_SPACE_LOW', $step->errorCode);
        $this->assertTrue($step->transient, 'Nouvel essai plus tard, pas un échec définitif.');
        $temp = rtrim((string) config('video.temp_path'), '/\\');
        $this->assertSame([], glob($temp.'/portability-*/*') ?: [], 'Aucune archive écrite sur le disque.');
    }

    public function test_deleted_media_free_their_space_only_after_the_physical_purge(): void
    {
        config(['quotas.storage.max_mb' => 100, 'media.purge_after_days' => 30]);
        $media = Media::factory()->for($this->user)->create(['size_bytes' => 40 * self::MB]);
        // Chemin réel du stockage (la factory utilise un chemin fictif que la purge ne connaît pas).
        $media->forceFill(['path' => app(MediaStorage::class)->pathFor($this->user->id, $media->id, MediaStorage::ORIGINAL, 'jpg')])->save();
        Storage::disk('media')->put($media->path, 'x');
        $quota = app(StorageQuota::class);

        $media->delete();
        $this->assertSame(40 * self::MB, $quota->storedBytes((string) $this->user->id), 'Suppression logique : fichier toujours présent, toujours compté.');

        $this->artisan('media:purge-deleted')->assertSuccessful();
        $this->assertSame(40 * self::MB, $quota->storedBytes((string) $this->user->id), 'Avant le délai de purge : toujours compté.');

        $this->travel(31)->days();
        $this->artisan('media:purge-deleted')->assertSuccessful();
        $this->assertSame(0, $quota->storedBytes((string) $this->user->id), 'Fichier purgé : espace rendu.');
        $this->assertFalse(Storage::disk('media')->exists($media->path));
    }
}
