<?php

namespace Tests\Feature\Clipper;

use App\Console\Commands\RegenerateSilentClips;
use App\Domain\Video\Enums\ClipStatus;
use App\Domain\Video\Rendering\FfmpegBinary;
use App\Jobs\RenderClipJob;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoClip;
use App\Models\VideoProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Régénération des clips antérieurs au contrôle audio (ADR-061). RÉEL : source et MP4 « ancien clip »
 * produits par FFmpeg (son à −40 dB, comme le défaut du volume à 1 %), mesures FFmpeg réelles.
 */
class RegenerateSilentClipsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        if (! app(FfmpegBinary::class)->isAvailable()) {
            $this->markTestSkipped('FFmpeg introuvable : test réel ignoré.');
        }
        Queue::fake();
        Storage::fake('media');
        $this->user = User::factory()->create();
    }

    public function test_a_clip_whose_sound_was_lost_is_rendered_again_without_touching_the_original_nor_duplicating(): void
    {
        $project = VideoProject::factory()->for($this->user)->clipping()->create([
            'source_media_id' => $this->media('sine=f=440:d=12')->id, 'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'status' => 'ready', 'duration_ms' => 12_000,
        ]);
        $lost = $this->renderedClip($project, $this->media('sine=f=440:d=6', 'volume=0.01')); // ancien défaut : −40 dB
        $fine = $this->renderedClip($project, $this->media('sine=f=440:d=6'));

        // Simulation : diagnostic seulement, aucune écriture.
        $this->artisan('clipper:regenerate-silent-clips')->expectsOutputToContain('son perdu')->expectsOutputToContain('Simulation')->assertSuccessful();
        $this->assertSame(2, VideoClip::query()->count());

        // Exécution : un NOUVEAU rendu pour le clip abîmé uniquement ; originaux inchangés.
        $this->artisan('clipper:regenerate-silent-clips', ['--execute' => true])->assertSuccessful();
        $new = VideoClip::query()->where('title', 'like', '%'.RegenerateSilentClips::SUFFIX)->get();
        $this->assertCount(1, $new);
        $this->assertSame([ClipStatus::Rendering, $lost->start_ms, $lost->end_ms], [$new[0]->status, $new[0]->start_ms, $new[0]->end_ms]);
        Queue::assertPushed(RenderClipJob::class, 1);
        $this->assertSame([ClipStatus::Rendered, $lost->output_media_id], [$lost->fresh()->status, $lost->fresh()->output_media_id]);
        $this->assertSame($fine->output_media_id, $fine->fresh()->output_media_id);

        // Relance : aucun doublon.
        $this->artisan('clipper:regenerate-silent-clips', ['--execute' => true])->expectsOutputToContain('déjà relancé')->assertSuccessful();
        $this->assertSame(3, VideoClip::query()->count());
        Queue::assertPushed(RenderClipJob::class, 1);
    }

    private function media(string $audio, ?string $filter = null): Media
    {
        $media = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        $media->forceFill(['path' => "users/{$this->user->id}/media/{$media->id}/original.mp4"])->save();
        $target = Storage::disk('media')->path($media->path);
        File::ensureDirectoryExists(dirname($target));
        (new Process([config('video.binaries.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=s=320x240:r=25:d=12', '-f', 'lavfi', '-i', $audio,
            ...($filter ? ['-af', $filter] : []), '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $target]))->setTimeout(120)->mustRun();

        return $media;
    }

    /** Clip publié avant le contrôle audio (aucune métadonnée « audio »), passage 2-8 s. */
    private function renderedClip(VideoProject $project, Media $output): VideoClip
    {
        $clip = new VideoClip(['title' => 'Ancien clip', 'start_ms' => 2_000, 'end_ms' => 8_000, 'aspect_ratio' => '9:16']);
        $clip->video_project_id = $project->id;
        $clip->forceFill(['source' => 'manual', 'status' => 'rendered', 'subtitles_enabled' => false, 'output_media_id' => $output->id])->save();

        return $clip;
    }
}
