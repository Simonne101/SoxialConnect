<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Media\Enums\MediaType;
use App\Domain\Media\Exceptions\MediaRejected;
use App\Domain\Media\Processing\ImageProcessor;
use App\Domain\Media\Storage\MediaStorage;
use App\Models\Media;

/**
 * Traitement d'un média stocké (appelé directement ou par ProcessMediaJob) :
 * - image : réencodage + vignette (ImageProcessor, GD) ;
 * - vidéo : aucune vignette ni durée sans FFmpeg (phase Video) — rien n'est simulé ;
 * - audio, document : aucun traitement.
 * Idempotent : peut être rejoué sans effet de bord.
 */
final class ProcessMedia
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly ImageProcessor $images,
    ) {}

    /** @param string|null $localPath copie locale déjà disponible (upload synchrone) */
    public function handle(Media $media, ?string $localPath = null): Media
    {
        $metadata = $media->metadata ?? [];

        if ($media->type === MediaType::Image) {
            $tmp = $localPath ?? $this->storage->copyToTemp($media->path);
            try {
                $result = $this->images->process($tmp, (string) $media->mime_type);
            } finally {
                if ($localPath === null) {
                    @unlink($tmp);
                }
            }

            if ($result->reencoded) {
                $this->storage->put($media->path, $result->original);
                $media->size_bytes = strlen($result->original);
            }
            $derivatives = [];
            if ($result->thumbnail !== null) {
                $thumbPath = $this->storage->pathFor($media->user_id, $media->id, MediaStorage::THUMBNAIL, (string) $result->thumbnailExtension);
                $this->storage->put($thumbPath, $result->thumbnail);
                $derivatives[MediaStorage::THUMBNAIL] = ['path' => $thumbPath, 'mime_type' => $result->thumbnailMime, 'size_bytes' => strlen($result->thumbnail)];
            }
            $media->width = $result->width;
            $media->height = $result->height;
            $media->derivatives = $derivatives ?: null;
            $metadata['reencoded'] = $result->reencoded;
            $metadata['image_processing'] = $this->images->isAvailable() ? 'gd' : 'unavailable';
        } elseif ($media->type === MediaType::Video) {
            // Sans FFmpeg : ni vignette ni durée (jamais de fausse miniature). Champs prêts pour la phase Video.
            $metadata['video_processing'] = 'unavailable';
        }

        $media->metadata = $metadata;
        $media->status = MediaStatus::Ready;
        $media->failure_reason = null;
        $media->save();

        return $media;
    }

    /** Échec définitif (job) : le média reste visible en « failed » avec un motif lisible. */
    public function fail(Media $media, \Throwable $e): void
    {
        $media->status = MediaStatus::Failed;
        $media->failure_reason = $e instanceof MediaRejected ? $e->getMessage() : 'Le traitement du fichier a échoué.';
        $media->save();
    }
}
