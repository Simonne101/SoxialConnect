<?php

namespace App\Jobs;

use App\Domain\Video\Enums\VideoSourceStatus;
use App\Domain\Video\Sources\VideoSourceService;
use App\Models\VideoSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Acquisition de la source vidéo d'un projet du Clipper (ADR-049) : une étape par exécution (vérifier
 * la propriété, solliciter ou interroger le fournisseur, importer, finaliser) ; l'attente d'un
 * fournisseur se fait par nouveaux jobs différés, jamais par une attente bloquante. File « video »
 * (connexion redis-video) : l'import d'un fichier volumineux peut être long.
 */
class AcquireVideoSourceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $sourceId)
    {
        $this->onConnection(config('video.render.connection'));
        $this->onQueue(config('video.render.queue'));
    }

    public function handle(VideoSourceService $sources): void
    {
        $sources->acquire($this->sourceId);
    }

    /**
     * Job interrompu (délai dépassé, worker arrêté pendant le téléchargement d'une archive…) : la source
     * ne reste jamais « en préparation » sans job pour la suivre ; une nouvelle recherche peut être relancée.
     */
    public function failed(?Throwable $exception): void
    {
        VideoSource::query()->whereKey($this->sourceId)
            ->whereIn('status', [VideoSourceStatus::Pending->value, VideoSourceStatus::Resolving->value, VideoSourceStatus::WaitingForProvider->value])
            ->update(['status' => VideoSourceStatus::Failed->value, 'next_check_at' => null, 'error_code' => 'ACQUISITION_ABORTED', 'error_message' => 'La récupération de la vidéo a été interrompue : relancez la recherche automatique.', 'updated_at' => now()]);
    }
}
