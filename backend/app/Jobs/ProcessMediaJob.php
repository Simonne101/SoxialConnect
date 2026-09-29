<?php

namespace App\Jobs;

use App\Domain\Media\Actions\ProcessMedia;
use App\Domain\Media\Exceptions\MediaRejected;
use App\Models\Media;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Traitement asynchrone d'un média (queue « media », docs/architecture.md §4.3), activé par
 * config('media.process_async'). Idempotent. Un fichier refusé échoue immédiatement, sans nouvel essai.
 */
class ProcessMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly string $mediaId)
    {
        $this->onQueue('media');
    }

    public function handle(ProcessMedia $process): void
    {
        $media = Media::query()->find($this->mediaId);
        if ($media === null) {
            return; // supprimé entre-temps
        }
        try {
            $process->handle($media);
        } catch (MediaRejected $e) {
            $process->fail($media, $e);
            $this->fail($e);
        }
    }

    public function failed(\Throwable $e): void
    {
        $media = Media::query()->find($this->mediaId);
        if ($media !== null && $media->status->value !== 'failed') {
            app(ProcessMedia::class)->fail($media, $e);
        }
    }
}
