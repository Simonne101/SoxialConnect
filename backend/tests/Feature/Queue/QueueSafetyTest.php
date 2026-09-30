<?php

namespace Tests\Feature\Queue;

use App\Jobs\ProcessMediaJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sûreté de la file d'attente (ADR-037) :
 * - retry_after plus long que le timeout de chaque job : un job en cours n'est jamais redonné
 *   à un autre worker (double exécution, double publication) ;
 * - after_commit : un job envoyé dans une transaction n'est poussé qu'après sa validation.
 */
class QueueSafetyTest extends TestCase
{
    use RefreshDatabase;

    /** Jobs de l'application et leur timeout (à compléter à chaque nouveau job). */
    private const JOBS = [ProcessMediaJob::class];

    public function test_retry_after_exceeds_every_job_timeout(): void
    {
        $longest = max(array_map(fn (string $job) => (new \ReflectionClass($job))->getDefaultProperties()['timeout'] ?? 60, self::JOBS));

        foreach (['redis', 'database'] as $connection) {
            $retryAfter = (int) config("queue.connections.{$connection}.retry_after");
            $this->assertGreaterThan($longest, $retryAfter, "queue.connections.{$connection}.retry_after doit dépasser {$longest} s.");
        }
    }

    public function test_jobs_are_pushed_only_after_the_transaction_commits(): void
    {
        foreach (['redis', 'database'] as $connection) {
            $this->assertTrue((bool) config("queue.connections.{$connection}.after_commit"), "queue.connections.{$connection}.after_commit");
        }

        config(['queue.default' => 'database']);

        // Transaction annulée : aucun job poussé.
        try {
            DB::transaction(function () {
                ProcessMediaJob::dispatch('00000000-0000-7000-8000-000000000001');
                $this->assertSame(0, DB::table('jobs')->count(), 'Le job ne doit pas être poussé avant la validation.');
                throw new \RuntimeException('annulation');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, DB::table('jobs')->count());

        // Transaction validée : le job est poussé, sur la queue « media ».
        DB::transaction(fn () => ProcessMediaJob::dispatch('00000000-0000-7000-8000-000000000002'));
        $this->assertSame(1, DB::table('jobs')->where('queue', 'media')->count());
    }
}
