<?php

namespace Tests\Feature\Clipper;

use App\Models\Media;
use App\Models\User;
use App\Models\VideoClip;
use App\Models\VideoProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Moteur réellement utilisé pour un clip publié (ADR-055, anomalie A2) : l'API indique un rendu de secours
 * FFmpeg UNIQUEMENT quand les métadonnées du média le confirment (composition.fallback_from = remotion),
 * jamais pour un clip Remotion, échoué, annulé, en cours ou antérieur à cette information. Données isolées
 * (médias de test, sans rendu) ; le pipeline réel du repli est couvert par ClipperRemotionPipelineTest.
 */
class ClipRenderEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private VideoProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        $source = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        $this->project = VideoProject::factory()->for($this->user)->clipping()->create(['source_media_id' => $source->id, 'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
        $this->project->forceFill(['status' => 'ready', 'duration_ms' => 60_000])->save();
    }

    /** @param array<string, mixed>|null $composition */
    private function clip(string $status, ?array $composition = null, bool $withOutput = true, ?string $errorCode = null): VideoClip
    {
        $output = null;
        if ($withOutput) {
            $output = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4',
                'metadata' => array_filter(['source' => 'youtube_clipper', 'composition' => $composition])]);
        }
        $clip = new VideoClip(['title' => "Clip {$status}", 'start_ms' => 1_000, 'end_ms' => 9_000, 'aspect_ratio' => '9:16']);
        $clip->video_project_id = $this->project->id;
        $clip->forceFill(['source' => 'ai', 'status' => $status, 'subtitles_enabled' => true, 'output_media_id' => $output?->id, 'error_code' => $errorCode,
            'error_message' => $errorCode !== null ? 'Génération annulée.' : null])->save();

        return $clip;
    }

    /** @return array<string, mixed> */
    private function clipJson(VideoClip $clip): array
    {
        $clips = collect($this->actingAs($this->user)->fromSpa()->getJson("/api/v1/clipper/projects/{$this->project->id}")->assertOk()->json('data.clips'));

        return (array) $clips->firstWhere('id', $clip->id);
    }

    public function test_a_render_not_yet_taken_by_a_worker_exposes_its_real_queue_wait(): void
    {
        // Incident du 2026-10-13 : 1 131 s d'attente sans worker vidéo actif, invisible dans l'interface.
        $queued = $this->clip('rendering', withOutput: false);
        $this->travel(6)->minutes();
        $this->assertGreaterThanOrEqual(360_000, $this->clipJson($queued)['queued_ms']);

        // Pris en charge par un worker : plus « en file ».
        $queued->forceFill(['started_at' => now(), 'render_stage' => 'preparing'])->save();
        $this->assertNull($this->clipJson($queued)['queued_ms']);
    }

    public function test_a_remotion_clip_is_reported_as_remotion_with_its_hook_and_never_as_a_fallback(): void
    {
        $clip = $this->clip('rendered', ['composer' => 'remotion', 'template' => 'clean-short', 'hook' => true, 'captions' => 3, 'crop' => 'center']);

        $json = $this->clipJson($clip);
        $this->assertSame(['engine' => 'remotion', 'fallback' => false, 'hook' => true, 'audio' => null], $json['render']);
        $this->assertNotNull($json['output']);
    }

    public function test_a_fallback_clip_is_flagged_only_when_the_metadata_confirms_it(): void
    {
        $fallback = $this->clip('rendered', ['composer' => 'ffmpeg', 'fallback_from' => 'remotion', 'fallback_reason' => 'REMOTION_RENDER_FAILED']);
        $this->assertSame(['engine' => 'ffmpeg', 'fallback' => true, 'hook' => false, 'audio' => null], $this->clipJson($fallback)['render']);

        // FFmpeg seul par choix (composition désactivée) : pas un repli.
        $ffmpeg = $this->clip('rendered', ['composer' => 'ffmpeg']);
        $this->assertSame(['engine' => 'ffmpeg', 'fallback' => false, 'hook' => false, 'audio' => null], $this->clipJson($ffmpeg)['render']);

        // Clip antérieur à la traçabilité du moteur : aucune supposition.
        $legacy = $this->clip('rendered');
        $this->assertSame(['engine' => null, 'fallback' => false, 'hook' => false, 'audio' => null], $this->clipJson($legacy)['render']);
    }

    public function test_failed_cancelled_or_rendering_clips_carry_no_engine_and_no_ready_output(): void
    {
        $failed = $this->clip('failed', withOutput: false, errorCode: 'REMOTION_RENDER_FAILED');
        $cancelled = $this->clip('failed', withOutput: false, errorCode: 'CANCELLED');
        $rendering = $this->clip('rendering', withOutput: false);
        // Média encore présent mais clip relancé (statut « rendering ») : jamais présenté comme prêt.
        $relaunched = $this->clip('rendering', ['composer' => 'ffmpeg', 'fallback_from' => 'remotion']);

        foreach ([$failed, $cancelled, $rendering, $relaunched] as $clip) {
            $json = $this->clipJson($clip);
            $this->assertNull($json['render'], $clip->title);
        }
        $this->assertNull($this->clipJson($failed)['output']);
        $this->assertSame('CANCELLED', $this->clipJson($cancelled)['error_code']);
    }

    public function test_an_unusable_output_file_is_never_reported_as_rendered_by_an_engine(): void
    {
        $clip = $this->clip('rendered', ['composer' => 'ffmpeg', 'fallback_from' => 'remotion']);
        Media::query()->whereKey($clip->output_media_id)->update(['scan_status' => 'infected']);

        $json = $this->clipJson($clip);
        $this->assertNull($json['output']);
        $this->assertNull($json['render']);
    }
}
