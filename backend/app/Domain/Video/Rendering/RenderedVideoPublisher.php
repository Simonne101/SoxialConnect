<?php

namespace App\Domain\Video\Rendering;

use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Media\Enums\MediaType;
use App\Domain\Media\Enums\ScanStatus;
use App\Domain\Media\Security\MediaSecurityScanner;
use App\Domain\Media\Security\ScanPolicy;
use App\Domain\Media\Storage\MediaStorage;
use App\Models\Media;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Publication d'un MP4 validé dans la médiathèque (ADR-045), commune au Video Studio et au YouTube
 * Clipper (ADR-046) : analyse antivirus (comme un envoi), média « video » prêt, stockage privé
 * (MediaStorage) du fichier et de sa vignette, puis enregistrement dans la transaction de l'appelant.
 * Aucun fichier orphelin : tout est supprimé si l'appelant renonce ou si une étape échoue.
 */
final class RenderedVideoPublisher
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly MediaSecurityScanner $scanner,
        private readonly ScanPolicy $scanPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata  origine du média (source, identifiants)
     * @param  Closure(Media): bool  $commit  exécutée dans une transaction : enregistre le média et
     *                                        l'état de l'appelant, ou renvoie false pour ne rien publier
     * @return Media|null le média publié, null si l'appelant a renoncé (annulation)
     *
     * @throws RenderFailure
     */
    public function publish(string $userId, string $title, RenderedVideo $video, array $metadata, Closure $commit): ?Media
    {
        if ($this->scanPolicy->isRequired() && ! $this->scanner->isAvailable()) {
            throw new RenderFailure('SCAN_UNAVAILABLE', 'L\'analyse de sécurité des fichiers est indisponible : la vidéo n\'a pas été publiée.');
        }
        $scan = $this->scanner->scan($video->path);
        if ($scan->status === ScanStatus::Infected || $scan->status === ScanStatus::Error) {
            throw new RenderFailure('SCAN_FAILED', 'La vidéo produite n\'a pas passé l\'analyse de sécurité.');
        }

        $media = new Media;
        $media->id = (string) Str::uuid7();
        $media->user_id = $userId;
        $media->type = MediaType::Video;
        $media->status = MediaStatus::Ready;
        $media->original_name = Str::limit(trim($title) ?: 'Vidéo', 120, '').'.mp4';
        $media->disk = $this->storage->diskName();
        $media->path = $this->storage->pathFor($userId, $media->id, MediaStorage::ORIGINAL, 'mp4');
        $media->mime_type = 'video/mp4';
        $media->extension = 'mp4';
        $media->size_bytes = $video->sizeBytes;
        $media->checksum_sha256 = hash_file('sha256', $video->path);
        $media->width = $video->probe->width;
        $media->height = $video->probe->height;
        $media->duration_ms = $video->probe->durationMs;
        $media->scan_status = $scan->status;
        $media->scanned_at = $scan->wasScanned() ? now() : null;
        $media->metadata = ['format' => 'mp4', ...$metadata, 'video_processing' => 'ffmpeg'];

        $this->putFile($media->path, $video->path);
        if ($video->thumbnailPath !== null) {
            $thumb = $this->storage->pathFor($userId, $media->id, MediaStorage::THUMBNAIL, 'jpg');
            $this->putFile($thumb, $video->thumbnailPath);
            $media->derivatives = [MediaStorage::THUMBNAIL => ['path' => $thumb, 'mime_type' => 'image/jpeg', 'size_bytes' => filesize($video->thumbnailPath)]];
        }

        try {
            $published = DB::transaction(fn () => $commit($media));
        } catch (Throwable $e) {
            $this->storage->deleteAll($media);
            throw $e;
        }
        if (! $published) {
            $this->storage->deleteAll($media); // aucun fichier orphelin

            return null;
        }

        return $media;
    }

    private function putFile(string $path, string $localFile): void
    {
        $stream = fopen($localFile, 'rb');
        try {
            $this->storage->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
