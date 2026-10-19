<?php

namespace App\Domain\Quota;

use Closure;

/**
 * Réservation des ressources d'un traitement coûteux (ADR-071) avant qu'il démarre : quota de stockage de
 * l'utilisateur (fichier produit) et disque du serveur (dossier de travail + sortie). Renvoie la fonction qui
 * libère les deux réservations — à appeler dans un « finally » (succès, échec ou annulation).
 */
class ResourceGuard
{
    public function __construct(
        private readonly StorageQuota $quota,
        private readonly DiskGuard $disk,
    ) {}

    /**
     * Rendu (clip, vidéo du Studio) : fichier publié dans la médiathèque de l'utilisateur.
     *
     * @return Closure(): void
     *
     * @throws QuotaExceeded
     * @throws DiskSpaceInsufficient
     */
    public function forRender(string $userId, string $operation): Closure
    {
        return $this->reserve($userId, (int) config('quotas.storage.render_reserve_mb', 300), (int) config('quotas.disk.render_reserve_mb', 2048), $operation);
    }

    /**
     * Analyse (audio extrait, transcription) : fichiers temporaires seulement, aucun quota de stockage.
     *
     * @return Closure(): void
     *
     * @throws DiskSpaceInsufficient
     */
    public function forAnalysis(string $operation): Closure
    {
        return $this->reserve(null, 0, (int) config('quotas.disk.analysis_reserve_mb', 512), $operation);
    }

    /** @return Closure(): void */
    private function reserve(?string $userId, int $quotaMb, int $diskMb, string $operation): Closure
    {
        $quotaId = $userId !== null && $quotaMb > 0 ? $this->quota->reserve($userId, $quotaMb * 1024 * 1024, $operation) : null;
        try {
            $diskId = $this->disk->reserve(max(0, $diskMb) * 1024 * 1024, $operation, (string) config('video.temp_path'));
        } catch (DiskSpaceInsufficient $e) {
            if ($userId !== null) {
                $this->quota->release($userId, $quotaId);
            }
            throw $e;
        }

        return function () use ($userId, $quotaId, $diskId): void {
            if ($userId !== null) {
                $this->quota->release($userId, $quotaId);
            }
            $this->disk->release($diskId);
        };
    }
}
