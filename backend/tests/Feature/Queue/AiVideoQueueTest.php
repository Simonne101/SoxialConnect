<?php

namespace Tests\Feature\Queue;

use App\Jobs\RenderVideoJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Files « ai » et « video » (ADR-042). Un rendu peut durer bien plus que le retry_after de la
 * connexion « redis » (360 s) : il passe par une connexion dédiée « redis-video », sans quoi un
 * rendu en cours serait redonné à un autre worker et exécuté deux fois.
 */
class AiVideoQueueTest extends TestCase
{
    public function test_video_renders_have_a_dedicated_connection_with_a_longer_retry_after(): void
    {
        $video = config('queue.connections.redis-video');
        $redis = config('queue.connections.redis');

        $this->assertSame('redis', $video['driver']);
        $this->assertSame($redis['connection'], $video['connection'], 'Même serveur Redis que les autres files.');
        $this->assertSame('video', $video['queue']);
        $this->assertTrue((bool) $video['after_commit'], 'Jobs poussés seulement après la validation de la transaction.');
        $this->assertGreaterThan((int) config('video.render.timeout_seconds'), (int) $video['retry_after'], 'retry_after > durée maximale d\'un rendu.');
        $this->assertGreaterThan((int) $redis['retry_after'], (int) $video['retry_after']);
        $this->assertSame(360, (int) $redis['retry_after'], 'La connexion existante est inchangée.');

        $this->assertSame('redis-video', config('video.render.connection'));
        $this->assertSame('video', config('video.render.queue'));
        $this->assertNotNull(Queue::connection('redis-video'), 'Connexion résolue par Laravel.');
    }

    public function test_the_render_job_runs_once_on_the_video_connection_within_its_retry_after(): void
    {
        $job = new RenderVideoJob('00000000-0000-0000-0000-000000000000');
        $defaults = (new \ReflectionClass(RenderVideoJob::class))->getDefaultProperties();

        $this->assertSame('redis-video', $job->connection);
        $this->assertSame('video', $job->queue);
        $this->assertSame(1, $defaults['tries'], 'Jamais de rendu relancé à l\'aveugle.');
        $this->assertSame((int) config('video.render.timeout_seconds'), $defaults['timeout']);
        $this->assertLessThan((int) config('queue.connections.redis-video.retry_after'), $defaults['timeout']);
    }

    public function test_ai_jobs_use_the_ai_queue_on_the_default_connection_within_its_retry_after(): void
    {
        $this->assertSame('ai', config('ai.queue.name'));
        $this->assertNull(config('ai.queue.connection'), 'Connexion par défaut (redis).');
        $this->assertLessThan((int) config('queue.connections.redis.retry_after'), (int) config('ai.timeout'));
    }

    public function test_the_dev_launcher_starts_optional_workers_on_the_right_connections(): void
    {
        $launcher = (string) file_get_contents(base_path('../scripts/dev.mjs'));

        // Worker existant inchangé.
        $this->assertStringContainsString("'queue:listen', 'redis', '--queue=publishing,default,media,analytics', '--sleep=1', '--timeout=300'", $launcher);
        // Workers optionnels : « ai » sur redis, « video » sur redis-video, timeouts sous les retry_after.
        $this->assertMatchesRegularExpression("/'queue:listen', 'redis', '--queue=ai', '--sleep=1', '--timeout=(\\d+)'/", $launcher);
        preg_match("/'queue:listen', 'redis-video', '--queue=video', '--sleep=3', '--timeout=(\\d+)'/", $launcher, $video);
        $this->assertNotEmpty($video, 'Le worker vidéo utilise la connexion redis-video.');
        $this->assertSame((int) config('video.render.timeout_seconds'), (int) $video[1]);
        $this->assertLessThan((int) config('queue.connections.redis-video.retry_after'), (int) $video[1]);
        $this->assertStringContainsString("extraWorkers.has('ai')", $launcher, 'Worker IA optionnel.');
        $this->assertStringContainsString("extraWorkers.has('video')", $launcher, 'Worker vidéo optionnel.');
    }
}
