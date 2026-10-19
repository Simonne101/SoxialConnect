<?php

namespace Tests\Feature\Config;

use App\Jobs\AnalyzeClipSourceJob;
use App\Jobs\GenerateAiImageJob;
use App\Jobs\RenderClipJob;
use ReflectionClass;
use Tests\TestCase;

/**
 * Cohérence des délais des workers du lanceur de développement (scripts/dev.mjs) avec les jobs et les
 * connexions Redis. `queue:listen` tue un job au bout de son --timeout, quel que soit le $timeout du job :
 * il faut donc $timeout du job ≤ --timeout du worker < retry_after de la connexion (sinon un job encore
 * en cours serait relancé), et l'appel fournisseur le plus long < $timeout du job.
 */
class DevLauncherWorkersTest extends TestCase
{
    private string $launcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->launcher = (string) file_get_contents(base_path('../scripts/dev.mjs'));
    }

    /** @return array{timeout: int, restart: bool} */
    private function worker(string $tag, string $connection, string $queue): array
    {
        $found = preg_match("/run\\('{$tag}', 'php', \\['artisan', 'queue:listen', '{$connection}', '--queue={$queue}',[^\\]]*'--timeout=(\\d+)'[^\\]]*\\][^\\n]*\\)/", $this->launcher, $m);
        $this->assertSame(1, $found, "Worker « {$tag} » introuvable dans scripts/dev.mjs.");

        return ['timeout' => (int) $m[1], 'restart' => str_contains($m[0], 'restart: true')];
    }

    private function jobTimeout(string $job): int
    {
        return (int) (new ReflectionClass($job))->getDefaultProperties()['timeout'];
    }

    public function test_the_ai_worker_lets_image_generation_finish_before_being_stopped(): void
    {
        $worker = $this->worker('worker-ai', 'redis', 'ai');
        $job = $this->jobTimeout(GenerateAiImageJob::class);

        $this->assertSame(300, $worker['timeout']);
        $this->assertGreaterThan((int) config('ai.images.timeout'), $job, 'Appel du fournisseur d\'images + import < délai du job.');
        $this->assertGreaterThanOrEqual($job, $worker['timeout'], 'Le worker ne coupe pas le job avant son propre délai.');
        $this->assertLessThan((int) config('queue.connections.redis.retry_after'), $worker['timeout'], 'Un job en cours n\'est jamais relancé.');
        $this->assertTrue($worker['restart'], 'Worker relancé automatiquement s\'il s\'arrête.');
    }

    public function test_the_video_worker_timeout_stays_consistent_with_renders_and_analyses(): void
    {
        $worker = $this->worker('worker-video', 'redis-video', 'video');

        foreach ([RenderClipJob::class, AnalyzeClipSourceJob::class] as $job) {
            $this->assertGreaterThanOrEqual($this->jobTimeout($job), $worker['timeout'], $job);
        }
        $this->assertLessThan((int) config('queue.connections.redis-video.retry_after'), $worker['timeout']);
        $this->assertTrue($worker['restart']);
    }

    public function test_ai_and_video_workers_start_by_default(): void
    {
        $this->assertMatchesRegularExpression("/const OPTIONAL_WORKERS = \\['ai', 'video'\\]/", $this->launcher);
        $this->assertStringContainsString("workersArg ? workersArg.slice('--workers='.length) : OPTIONAL_WORKERS.join(',')", $this->launcher);
    }
}
