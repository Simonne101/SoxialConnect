<?php

namespace Tests\Feature\Clipper;

use App\Domain\Video\Clipper\ClipLimits;
use App\Domain\Video\Clipper\Transcription\WhisperCppTranscriber;
use App\Domain\Video\Enums\ClipStatus;
use App\Domain\Video\Rendering\FfmpegBinary;
use App\Domain\Video\Rendering\MediaProbe;
use App\Http\Resources\MediaResource;
use App\Jobs\AnalyzeClipSourceJob;
use App\Jobs\RenderClipJob;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoClip;
use App\Models\VideoProject;
use App\Models\VideoTranscript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Clipper SANS IA (ADR-047), chaîne RÉELLE : FFmpeg/FFprobe exécutés, aucune requête externe.
 * Passage manuel dans les trois formats, audio conservé, analyse locale sur les pauses, sous-titres
 * incrustés, transcription locale whisper.cpp (si installée). Lancer : php artisan test --group=ffmpeg
 */
#[Group('ffmpeg')]
class ClipperLocalPipelineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $temp;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $binaries = app(FfmpegBinary::class);
        if (! $binaries->isAvailable() || ! $binaries->isProbeAvailable()) {
            $this->markTestSkipped('FFmpeg/FFprobe introuvables (VIDEO_FFMPEG_BINARY, VIDEO_FFPROBE_BINARY) : test d\'intégration réel ignoré.');
        }
        Queue::fake();
        Storage::fake('media');
        Http::preventStrayRequests();
        $this->temp = storage_path('framework/testing/clipper-local');
        config([
            'video.temp_path' => $this->temp,
            // Aucune IA : tout doit fonctionner sans fournisseur.
            'ai.default_provider' => null,
            'video.clipper.transcription' => 'none',
            'video.clipper.analysis' => 'auto',
        ]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temp ?? '');
        parent::tearDown();
    }

    private function api(): static
    {
        return $this->actingAs($this->user)->fromSpa();
    }

    /**
     * Vidéo de test générée par FFmpeg et rangée dans la médiathèque factice.
     *
     * @param  list<string>  $audio  entrée audio FFmpeg (par défaut : son continu)
     */
    private function sourceVideo(int $seconds, array $audio = [], array $audioFilter = []): Media
    {
        $media = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'width' => 640, 'height' => 360]);
        $media->forceFill(['path' => "users/{$this->user->id}/media/{$media->id}/original.mp4"])->save();
        $target = Storage::disk('media')->path($media->path);
        File::ensureDirectoryExists(dirname($target));
        $audio = $audio ?: ['-f', 'lavfi', '-i', "sine=f=440:d={$seconds}"];
        (new Process([config('video.binaries.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', "testsrc2=s=640x360:r=25:d={$seconds}", ...$audio, ...$audioFilter,
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $target]))->setTimeout(300)->mustRun();

        return $media;
    }

    private function project(Media $source, ?int $durationMs = null): VideoProject
    {
        $project = VideoProject::factory()->for($this->user)->clipping()->create([
            'source_media_id' => $source->id,
            'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'title' => 'Source locale',
        ]);
        if ($durationMs !== null) {
            $project->forceFill(['status' => 'ready', 'duration_ms' => $durationMs])->save();
        }

        return $project;
    }

    private function analyze(VideoProject $project): VideoProject
    {
        app()->call([new AnalyzeClipSourceJob($project->id), 'handle']);

        return $project->fresh();
    }

    private function clip(VideoProject $project, array $body): VideoClip
    {
        $id = $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/clips", $body)->assertStatus(202)->json('data.id');
        app()->call([new RenderClipJob($id), 'handle']);

        return VideoClip::findOrFail($id);
    }

    private function path(Media $media): string
    {
        return Storage::disk('media')->path($media->path);
    }

    /** Luminance moyenne (0-255) de la bande basse d'une image du clip, relevée par FFmpeg. */
    private function bottomLuma(Media $media, float $at): int
    {
        $process = new Process([config('video.binaries.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-ss', (string) $at, '-i', $this->path($media),
            '-frames:v', '1', '-vf', 'crop=iw:ih*0.22:0:ih*0.70,scale=1:1,format=gray', '-f', 'rawvideo', '-']);
        $process->mustRun();

        return ord($process->getOutput()[0] ?? "\0");
    }

    public function test_manual_clips_are_rendered_in_every_format_with_their_audio(): void
    {
        $project = $this->project($this->sourceVideo(90), 90_000);

        foreach ([['9:16', 0, 10_000, [1080, 1920]], ['1:1', 10_000, 40_000, [1080, 1080]], ['16:9', 20_000, 80_000, [1920, 1080]]] as [$ratio, $start, $end, $size]) {
            $clip = $this->clip($project, ['start_ms' => $start, 'end_ms' => $end, 'aspect_ratio' => $ratio]);
            $this->assertSame(ClipStatus::Rendered, $clip->status, "{$ratio} : ".$clip->error_message);
            $this->assertNull($clip->render_stage, 'Étape effacée une fois le clip prêt.');
            $media = Media::findOrFail($clip->output_media_id);
            $probe = app(MediaProbe::class)->probe($this->path($media));
            $this->assertSame($size, [$probe->width, $probe->height], $ratio);
            $this->assertSame('h264', $probe->videoCodec);
            $this->assertTrue($probe->hasAudio, "{$ratio} : audio conservé");
            $this->assertSame('aac', $probe->audioCodec);
            $this->assertEqualsWithDelta($end - $start, $probe->durationMs, 250, $ratio);
            $this->assertLessThanOrEqual(ClipLimits::MAX_CLIP_MS, $probe->durationMs);
            $this->assertSame('youtube_clipper', $media->metadata['source']);
        }

        // Média normal : URL signée lisible, refusée sans signature.
        $url = (new MediaResource($media))->resolve()['url'];
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $this->get(strtok($url, '?'))->assertForbidden();
        $this->assertSame([], File::directories($this->temp), 'Aucun dossier de travail restant.');
    }

    public function test_a_source_too_short_fails_cleanly(): void
    {
        $project = $this->analyze($this->project($this->sourceVideo(2)));

        $this->assertSame('failed', $project->status->value);
        $this->assertSame('SOURCE_TOO_SHORT', $project->settings['analysis_error_code']);
        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/clips", ['start_ms' => 0, 'end_ms' => 3_000])->assertStatus(409);
    }

    public function test_local_analysis_proposes_passages_on_natural_pauses_without_any_ai(): void
    {
        // 14 s de son, 6 s de silence, en boucle : des pauses mesurables.
        $source = $this->sourceVideo(90, [], ['-af', "volume='if(lt(mod(t,20),14),1,0)':eval=frame"]);
        $project = $this->analyze($this->project($source));

        $this->assertSame('ready', $project->status->value, (string) $project->error);
        $this->assertSame('local', $project->settings['analysis']['mode']);
        Http::assertNothingSent();
        $clips = VideoClip::query()->where('video_project_id', $project->id)->orderBy('start_ms')->get();
        $this->assertNotEmpty($clips);
        $previousEnd = -1;
        foreach ($clips as $clip) {
            $this->assertSame('auto', $clip->source->value, 'Jamais présenté comme une IA.');
            $this->assertGreaterThanOrEqual(0, $clip->start_ms);
            $this->assertLessThanOrEqual($project->duration_ms, $clip->end_ms);
            $this->assertLessThanOrEqual(ClipLimits::MAX_CLIP_MS, $clip->end_ms - $clip->start_ms);
            $this->assertGreaterThanOrEqual($previousEnd, $clip->start_ms, 'Aucun chevauchement.');
            $this->assertStringContainsString('sans IA', (string) $clip->reason);
            $previousEnd = $clip->end_ms;
        }
        $this->api()->getJson("/api/v1/clipper/projects/{$project->id}")->assertOk()->assertJsonPath('data.analysis.mode', 'local')->assertJsonPath('data.clips.0.source', 'auto');

        // Une proposition locale se génère comme les autres.
        $id = $clips->first()->id;
        $this->api()->postJson("/api/v1/clipper/clips/{$id}/render")->assertStatus(202);
        app()->call([new RenderClipJob($id), 'handle']);
        $this->assertSame(ClipStatus::Rendered, VideoClip::findOrFail($id)->status);
    }

    public function test_subtitles_are_burned_from_the_transcript_and_require_one(): void
    {
        $project = $this->project($this->sourceVideo(30), 30_000);
        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/clips", ['start_ms' => 0, 'end_ms' => 10_000, 'subtitles' => true])
            ->assertStatus(422)->assertJsonPath('code', 'SUBTITLES_UNAVAILABLE');

        VideoTranscript::query()->create(['video_project_id' => $project->id, 'language' => 'fr', 'provider' => 'test', 'status' => 'ready', 'full_text' => 'x', 'segments' => [
            ['start_ms' => 500, 'end_ms' => 9_500, 'text' => 'Un sous-titre lisible, avec des accents : été, « guillemets » et un texte assez long pour passer à la ligne automatiquement.'],
        ]]);
        $this->api()->getJson("/api/v1/clipper/projects/{$project->id}")->assertJsonPath('data.has_transcript', true);

        $with = $this->clip($project, ['start_ms' => 0, 'end_ms' => 10_000, 'subtitles' => true, 'subtitle_style' => ['position' => 'bottom', 'size' => 'large']]);
        $without = $this->clip($project, ['start_ms' => 0, 'end_ms' => 10_000]);
        $this->assertSame(ClipStatus::Rendered, $with->status, (string) $with->error_message);
        $this->assertSame(ClipStatus::Rendered, $without->status);
        $this->assertTrue($with->subtitles_enabled);
        $this->assertEqualsCanonicalizing(['position' => 'bottom', 'size' => 'large'], $with->subtitle_style);

        $withMedia = Media::findOrFail($with->output_media_id);
        $this->assertNotSame(
            $this->bottomLuma($withMedia, 5),
            $this->bottomLuma(Media::findOrFail($without->output_media_id), 5),
            'Bande des sous-titres visible sur l\'image.',
        );
        $this->assertSame($this->bottomLuma($withMedia, 0.1), $this->bottomLuma(Media::findOrFail($without->output_media_id), 0.1), 'Aucun sous-titre avant le premier segment.');
    }

    public function test_passages_proposed_from_the_link_are_checked_against_the_attached_source(): void
    {
        // Projet créé depuis le lien seul ; passages proposés par l'IA (ADR-048), durée encore inconnue.
        $project = VideoProject::factory()->for($this->user)->clipping()->create(['source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title' => 'Lien seul', 'source_media_id' => null]);
        $project->forceFill(['status' => 'ready', 'settings' => ['analysis' => ['input' => 'youtube_url', 'mode' => 'ai', 'notes' => []]]])->save();
        foreach ([[5_000, 25_000, 'Dans la vidéo'], [50_000, 70_000, 'Au-delà de la vidéo']] as [$start, $end, $title]) {
            $clip = new VideoClip(['title' => $title, 'start_ms' => $start, 'end_ms' => $end, 'aspect_ratio' => '9:16']);
            $clip->video_project_id = $project->id;
            $clip->forceFill(['source' => 'ai', 'status' => 'suggested', 'score' => 80])->save();
        }

        // La vraie vidéo (40 s) est jointe : mesurée par FFprobe, passages revalidés.
        $this->api()->putJson("/api/v1/clipper/projects/{$project->id}/source", ['media_id' => $this->sourceVideo(40)->id])->assertOk();
        $project = $this->analyze($project);

        $this->assertSame('ready', $project->status->value);
        $this->assertEqualsWithDelta(40_000, $project->duration_ms, 150);
        $this->assertSame('youtube_url', $project->settings['analysis']['input']);
        $this->assertSame(['Dans la vidéo'], VideoClip::query()->where('video_project_id', $project->id)->pluck('title')->all(), 'Passage hors durée réelle écarté.');

        $id = VideoClip::query()->where('video_project_id', $project->id)->value('id');
        $this->api()->postJson("/api/v1/clipper/clips/{$id}/render")->assertStatus(202);
        app()->call([new RenderClipJob($id), 'handle']);
        $this->assertSame(ClipStatus::Rendered, VideoClip::findOrFail($id)->status);
    }

    public function test_link_passages_are_recalibrated_on_the_transcript_and_the_transcript_is_reused(): void
    {
        // Projet créé depuis le lien : passage estimé par l'IA à 7,4 s → 21,3 s (bornes imprécises).
        $project = VideoProject::factory()->for($this->user)->clipping()->create(['source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title' => 'Lien seul', 'source_media_id' => null]);
        $project->forceFill(['status' => 'ready', 'settings' => ['analysis' => ['input' => 'youtube_url', 'mode' => 'ai', 'notes' => []]]])->save();
        $clip = new VideoClip(['title' => 'Conseil clé', 'reason' => 'Accroche', 'start_ms' => 7_400, 'end_ms' => 21_300, 'aspect_ratio' => '9:16']);
        $clip->video_project_id = $project->id;
        $clip->forceFill(['source' => 'ai', 'status' => 'suggested', 'score' => 91])->save();

        // Source jointe (40 s) ; transcription déjà faite pour CETTE source (relance) : réutilisée, jamais refaite.
        $source = $this->sourceVideo(40);
        $this->api()->putJson("/api/v1/clipper/projects/{$project->id}/source", ['media_id' => $source->id])->assertOk();
        $project->refresh()->forceFill(['settings' => [...$project->settings, 'transcript_media_id' => $source->id]])->save();
        VideoTranscript::query()->create(['video_project_id' => $project->id, 'language' => 'fr', 'provider' => 'whisper.cpp', 'status' => 'ready', 'full_text' => '…', 'segments' => [
            ['start_ms' => 0, 'end_ms' => 6_500, 'text' => 'Bienvenue à tous.'],
            ['start_ms' => 6_500, 'end_ms' => 13_000, 'text' => 'Premier conseil : publiez souvent.', 'words' => [[6_500, 7_200, 'Premier'], [7_200, 8_000, 'conseil']]],
            ['start_ms' => 13_000, 'end_ms' => 20_800, 'text' => 'Trois fois par semaine, au même moment.'],
            ['start_ms' => 20_800, 'end_ms' => 30_000, 'text' => 'Deuxième conseil : soignez votre accroche.'],
        ]]);

        $project = $this->analyze($project);

        $this->assertSame('ready', $project->status->value);
        $this->assertSame('youtube_url', $project->settings['analysis']['input']);
        $this->assertSame(1, $project->settings['analysis']['recalibrated']);
        $this->assertContains('Transcription existante réutilisée.', $project->settings['analysis']['notes']);
        $this->assertDatabaseMissing('ai_generations', ['video_project_id' => $project->id, 'task' => 'transcription']);
        $recalibrated = VideoClip::query()->where('video_project_id', $project->id)->sole();
        $this->assertSame([6_500, 20_800], [$recalibrated->start_ms, $recalibrated->end_ms], 'Début et fin de phrase, jamais au milieu d\'un mot.');
        $this->assertSame('Conseil clé', $recalibrated->title);
        $this->assertEquals(91, $recalibrated->score);

        // Clip rendu à partir du passage recalé, avec les sous-titres de la transcription.
        $this->api()->postJson("/api/v1/clipper/clips/{$recalibrated->id}/render", ['aspect_ratio' => '1:1', 'subtitles' => true])->assertStatus(202);
        app()->call([new RenderClipJob($recalibrated->id), 'handle']);
        $rendered = VideoClip::findOrFail($recalibrated->id);
        $this->assertSame(ClipStatus::Rendered, $rendered->status, (string) $rendered->error_message);
        $probe = app(MediaProbe::class)->probe($this->path(Media::findOrFail($rendered->output_media_id)));
        $this->assertSame([1080, 1080], [$probe->width, $probe->height]);
        $this->assertTrue($probe->hasAudio);
        $this->assertEqualsWithDelta(14_300, $probe->durationMs, 250);
    }

    #[Group('whisper')]
    public function test_local_whisper_transcription_feeds_the_local_analysis_and_subtitles(): void
    {
        if (! app(WhisperCppTranscriber::class)->isInstalled()) {
            $this->markTestSkipped('whisper.cpp non installé (video.clipper.whisper) : transcription locale réelle ignorée.');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Parole de test produite par la synthèse vocale de Windows.');
        }
        $wav = $this->speech([
            'Premier conseil : publiez régulièrement, trois fois par semaine.',
            'Deuxième conseil, le plus important : les vidéos courtes retiennent mieux l\'attention.',
            'Troisième conseil : répondez toujours aux commentaires de votre communauté.',
        ]);
        $source = $this->sourceVideo(40, ['-i', $wav]);
        config(['video.clipper.transcription' => 'local']);

        $project = $this->analyze($this->project($source));

        $this->assertSame('ready', $project->status->value, (string) $project->error);
        $this->assertSame('local', $project->settings['analysis']['transcription']);
        $this->assertSame('whisper.cpp', $project->settings['analysis']['transcription_engine']);
        $transcript = VideoTranscript::query()->where('video_project_id', $project->id)->firstOrFail();
        $this->assertSame('fr', $transcript->language);
        $this->assertStringContainsStringIgnoringCase('conseil', $transcript->full_text);
        $this->assertDatabaseHas('ai_generations', ['video_project_id' => $project->id, 'task' => 'transcription', 'provider' => 'whisper.cpp', 'status' => 'succeeded']);
        Http::assertNothingSent();

        $suggestion = VideoClip::query()->where('video_project_id', $project->id)->orderByDesc('score')->firstOrFail();
        $this->assertSame('auto', $suggestion->source->value);
        $this->assertStringContainsStringIgnoringCase('conseil', (string) $suggestion->topic);

        $this->api()->postJson("/api/v1/clipper/clips/{$suggestion->id}/render", ['subtitles' => true])->assertStatus(202);
        app()->call([new RenderClipJob($suggestion->id), 'handle']);
        $this->assertSame(ClipStatus::Rendered, $suggestion->fresh()->status, (string) $suggestion->fresh()->error_message);
    }

    /** Parole française synthétisée (voix Windows), WAV dans le dossier de test. */
    private function speech(array $sentences): string
    {
        File::ensureDirectoryExists($this->temp);
        $wav = $this->temp.DIRECTORY_SEPARATOR.'speech.wav';
        $script = $this->temp.DIRECTORY_SEPARATOR.'speech.ps1';
        $text = implode(' ', array_map(fn ($s) => str_replace("'", "''", $s), $sentences));
        // BOM UTF-8 : Windows PowerShell 5.1 lit sinon le script en ANSI (accents altérés).
        file_put_contents($script, "\u{FEFF}Add-Type -AssemblyName System.Speech\n\$s = New-Object System.Speech.Synthesis.SpeechSynthesizer\n"
            ."\$v = \$s.GetInstalledVoices() | Where-Object { \$_.VoiceInfo.Culture.Name -eq 'fr-FR' } | Select-Object -First 1\n"
            ."if (\$v) { \$s.SelectVoice(\$v.VoiceInfo.Name) }\n\$s.SetOutputToWaveFile('{$wav}')\n\$s.Speak('{$text}')\n\$s.Dispose()\n");
        (new Process(['powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $script]))->setTimeout(120)->mustRun();
        if (! is_file($wav) || filesize($wav) < 10_000) {
            $this->markTestSkipped('Synthèse vocale Windows indisponible.');
        }

        return $wav;
    }
}
