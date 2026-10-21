<?php

namespace App\Jobs;

use App\Domain\Video\Clipper\ClipAnalysisRunner;
use App\Models\VideoProject;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Analyse d'une vidéo source du YouTube Clipper (ADR-046) : FFprobe, extraction audio (FFmpeg),
 * transcription et choix des passages par l'IA. Même file que les rendus (« video », connexion
 * redis-video, retry_after 1 900 s) : transcrire une longue vidéo peut prendre plusieurs minutes.
 */
class AnalyzeClipSourceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $projectId)
    {
        $this->onConnection(config('video.render.connection'));
        $this->onQueue(config('video.render.queue'));
    }

    public function handle(ClipAnalysisRunner $runner): void
    {
        $runner->run($this->projectId);
    }

    public function failed(?Throwable $exception): void
    {
        $project = VideoProject::query()->find($this->projectId);
        if ($project !== null) {
            app(ClipAnalysisRunner::class)->fail($project, 'ANALYSIS_ABORTED', 'L\'analyse a été interrompue.');
        }
        File::deleteDirectory(rtrim((string) config('video.temp_path'), '/\\').DIRECTORY_SEPARATOR.$this->projectId);
    }
}
