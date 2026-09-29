<?php

namespace App\Domain\Media\Storage;

use App\Models\Media;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Seul point d'accès au stockage des médias (docs/architecture.md §4.6).
 * - Clés générées côté serveur : users/{user}/media/{media}/{variant}.{ext} — jamais le nom d'origine.
 * - Disque configurable (config('media.disk')) : local privé aujourd'hui, S3 / MinIO plus tard
 *   sans changement des appelants.
 * - Lecture par URL signée courte : route applicative signée pour un disque local,
 *   URL temporaire du fournisseur pour un disque qui la supporte (S3).
 */
final class MediaStorage
{
    public const ORIGINAL = 'original';

    public const THUMBNAIL = 'thumbnail';

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function diskName(): string
    {
        return (string) config('media.disk', 'media');
    }

    /** Clé d'objet d'une variante ; les identifiants sont des UUID générés par le serveur. */
    public function pathFor(string $userId, string $mediaId, string $variant, string $extension): string
    {
        foreach ([$userId, $mediaId] as $id) {
            if (! preg_match('/^[0-9a-f-]{36}$/i', $id)) {
                throw new \InvalidArgumentException('Identifiant de stockage invalide.');
            }
        }
        if (! preg_match('/^[a-z0-9]{2,5}$/', $extension) || ! in_array($variant, [self::ORIGINAL, self::THUMBNAIL], true)) {
            throw new \InvalidArgumentException('Variante ou extension de stockage invalide.');
        }

        return "users/{$userId}/media/{$mediaId}/{$variant}.{$extension}";
    }

    /** @param resource|string $contents */
    public function put(string $path, $contents): void
    {
        $this->disk()->put($path, $contents, ['visibility' => 'private']);
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    /** @return resource|null */
    public function readStream(string $path)
    {
        return $this->disk()->readStream($path);
    }

    /** Copie un objet stocké dans un fichier temporaire local (traitement en file d'attente). */
    public function copyToTemp(string $path): string
    {
        $stream = $this->readStream($path) ?? throw new \RuntimeException('Fichier média introuvable dans le stockage.');
        $tmp = tempnam(sys_get_temp_dir(), 'sxc-media-');
        $out = fopen($tmp, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);

        return $tmp;
    }

    /** Supprime tous les fichiers d'un média (dossier users/{user}/media/{media}). */
    public function deleteAll(Media $media): void
    {
        $directory = dirname($this->pathFor($media->user_id, $media->id, self::ORIGINAL, 'bin'));
        $this->disk()->deleteDirectory($directory);
    }

    /** Chemin de la variante demandée, ou null si elle n'existe pas pour ce média. */
    public function variantPath(Media $media, string $variant): ?string
    {
        return match ($variant) {
            self::ORIGINAL => $media->path,
            self::THUMBNAIL => $media->derivatives[self::THUMBNAIL]['path'] ?? null,
            default => null,
        };
    }

    /** URL de lecture temporaire ; null si la variante n'existe pas. */
    public function temporaryUrl(Media $media, string $variant, bool $download = false): ?string
    {
        $path = $this->variantPath($media, $variant);
        if ($path === null) {
            return null;
        }
        $expiresAt = now()->addMinutes((int) config('media.url_ttl_minutes', 15));

        // Fournisseur compatible (S3, MinIO) : URL présignée directe, sans passer par l'application.
        $disk = $this->disk();
        // (Disque local : toujours la route signée de l'application.)
        $isLocal = config("filesystems.disks.{$this->diskName()}.driver") === 'local';
        if (! $isLocal && $disk instanceof FilesystemAdapter && $disk->providesTemporaryUrls()) {
            return $disk->temporaryUrl($path, $expiresAt, array_filter([
                'ResponseContentType' => $variant === self::THUMBNAIL ? 'image/webp' : $media->mime_type,
                'ResponseContentDisposition' => $download ? 'attachment; filename="'.$media->downloadName().'"' : null,
            ]));
        }

        return URL::temporarySignedRoute('api.v1.media.file', $expiresAt, array_filter([
            'media' => $media->id,
            'variant' => $variant,
            'download' => $download ? 1 : null,
        ]));
    }
}
