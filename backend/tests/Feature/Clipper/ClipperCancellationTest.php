<?php

namespace Tests\Feature\Clipper;

use App\Domain\Video\Clipper\ClipperService;
use App\Domain\Video\Enums\VideoSourceStatus;
use App\Domain\Video\Sources\VideoSourceService;
use App\Jobs\AnalyzeClipSourceJob;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoClip;
use App\Models\VideoProject;
use App\Models\VideoSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Annulation coopérative d'un projet du Clipper (ADR-051) : état « cancelled », aucune étape suivante,
 * job retardataire sans effet, rendus en cours arrêtés, médias déjà publiés conservés. HTTP simulé.
 */
class ClipperCancellationTest extends TestCase
{
    use RefreshDatabase;

    private const GEMINI = 'generativelanguage.googleapis.com/v1beta/interactions';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config([
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

    private function project(string $status = 'pending', array $attributes = []): VideoProject
    {
        $project = VideoProject::factory()->for($this->user)->clipping()->create(['source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title' => 'Conférence', 'source_media_id' => null, ...$attributes]);
        $project->forceFill(['status' => $status])->save();

        return $project;
    }

    private function clip(VideoProject $project, string $status, ?string $mediaId = null): VideoClip
    {
        $clip = new VideoClip(['title' => $status, 'start_ms' => 0, 'end_ms' => 10_000, 'aspect_ratio' => '9:16']);
        $clip->video_project_id = $project->id;
        $clip->forceFill(['source' => 'manual', 'status' => $status, 'output_media_id' => $mediaId])->save();

        return $clip;
    }

    private function source(VideoProject $project, string $status): VideoSource
    {
        $source = new VideoSource;
        $source->forceFill(['user_id' => $this->user->id, 'video_project_id' => $project->id, 'provider' => 'youtube', 'external_reference' => 'dQw4w9WgXcQ', 'source_type' => 'youtube_video', 'ownership_status' => 'unverified', 'status' => $status])->save();

        return $source;
    }

    public function test_cancelling_stops_every_running_stage_but_keeps_published_media(): void
    {
        $project = $this->project('transcribing');
        $published = Media::factory()->for($this->user)->create(['type' => 'video']);
        $done = $this->clip($project, 'rendered', $published->id);
        $rendering = $this->clip($project, 'rendering');
        $source = $this->source($project, 'waiting_for_provider');

        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/cancel")->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.source_status.status', 'failed')
            ->assertJsonPath('data.source_status.error_code', 'CANCELLED');

        $this->assertSame('failed', $rendering->fresh()->status->value);
        $this->assertSame('CANCELLED', $rendering->fresh()->error_code);
        $this->assertSame('rendered', $done->fresh()->status->value, 'Clip déjà prêt : intact.');
        $this->assertNotSoftDeleted('media', ['id' => $published->id]);
        $this->assertSame(VideoSourceStatus::Failed, $source->fresh()->status);

        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/cancel")->assertStatus(409)->assertJsonPath('code', 'NOTHING_TO_CANCEL');
        $this->api(User::factory()->create())->postJson("/api/v1/clipper/projects/{$project->id}/cancel")->assertNotFound();
    }

    public function test_late_jobs_do_nothing_and_no_new_step_starts_after_cancellation(): void
    {
        $project = $this->project('pending');
        $source = $this->source($project, 'pending');
        app(ClipperService::class)->cancel($project);

        // Jobs déjà en file au moment de l'annulation : aucun effet, aucun appel externe.
        app()->call([new AnalyzeClipSourceJob($project->id), 'handle']);
        app(VideoSourceService::class)->acquire($source->id);
        Http::assertNothingSent();
        $this->assertSame('cancelled', $project->fresh()->status->value);
        $this->assertSame(0, VideoClip::query()->where('video_project_id', $project->id)->count());

        // Plus aucun rendu ne démarre.
        $ready = $this->project('cancelled', ['source_media_id' => Media::factory()->for($this->user)->create(['type' => 'video'])->id, 'duration_ms' => 90_000]);
        $this->api()->postJson("/api/v1/clipper/projects/{$ready->id}/clips", ['start_ms' => 0, 'end_ms' => 10_000])
            ->assertStatus(409)->assertJsonPath('code', 'PROJECT_CANCELLED');
        Queue::assertNothingPushed();

        // Reprise explicite : relancer l'analyse.
        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/reanalyze")->assertStatus(202)->assertJsonPath('data.status', 'pending');
    }

    public function test_a_cancellation_during_the_ai_call_discards_its_result(): void
    {
        $project = $this->project('pending');
        // L'utilisateur annule PENDANT que le modèle répond : le résultat arrive, mais n'est jamais enregistré.
        Http::fake([self::GEMINI => function (Request $request) use ($project) {
            app(ClipperService::class)->cancel($project);

            return Http::response(['id' => 'v1_x', 'status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => json_encode(['clips' => [[
                'start_ms' => 10_000, 'end_ms' => 40_000, 'title' => 'Passage', 'hook' => 'h', 'topic' => 't', 'reason' => 'r', 'confidence' => 80,
                'criteria' => ['hook' => 80, 'value' => 80, 'autonomy' => 80, 'emotion' => 80, 'rhythm' => 80, 'quality' => 80],
            ]]])]]]]]);
        }]);

        app()->call([new AnalyzeClipSourceJob($project->id), 'handle']);

        Http::assertSentCount(1);
        $this->assertSame('cancelled', $project->fresh()->status->value);
        $this->assertSame(0, VideoClip::query()->where('video_project_id', $project->id)->count(), 'Aucun passage enregistré après annulation.');
    }
}
