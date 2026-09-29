<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Media\Enums\MediaType;
use App\Domain\Media\Storage\MediaStorage;
use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lecture d'un fichier par URL signée courte (générée après autorisation par MediaResource).
 * Jamais de chemin fourni par le client : le média et la variante désignent un chemin connu.
 * Un média supprimé, en quarantaine ou non prêt n'est jamais servi (404).
 */
class MediaFileController extends Controller
{
    public function show(Request $request, Media $media, string $variant, MediaStorage $storage): Response
    {
        $path = $storage->variantPath($media, $variant);
        if (! $media->isUsable() || $path === null || ! $storage->exists($path)) {
            abort(404);
        }

        $isThumbnail = $variant === MediaStorage::THUMBNAIL;
        $mime = $isThumbnail ? ($media->derivatives[MediaStorage::THUMBNAIL]['mime_type'] ?? 'image/webp') : (string) $media->mime_type;
        // Documents toujours en téléchargement ; les autres en affichage sauf demande explicite.
        $attachment = $request->boolean('download') || ($media->type === MediaType::Document && ! $isThumbnail);

        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => ($attachment ? 'attachment' : 'inline').'; filename="'.$media->downloadName().'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age='.((int) config('media.url_ttl_minutes', 15) * 60),
        ];

        // Disque local : réponse fichier (gère les requêtes Range pour la lecture vidéo et audio).
        $disk = Storage::disk($storage->diskName());
        if (method_exists($disk, 'path') && config("filesystems.disks.{$storage->diskName()}.driver") === 'local') {
            // BinaryFileResponse se déclare « public » par défaut : on force un cache privé.
            return response()->file($disk->path($path), $headers)
                ->setPrivate()
                ->setMaxAge((int) config('media.url_ttl_minutes', 15) * 60);
        }

        $stream = $storage->readStream($path) ?? abort(404);

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }
}
