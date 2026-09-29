<?php

namespace App\Domain\Media\Upload;

use App\Domain\Media\Enums\MediaType;
use App\Domain\Media\Exceptions\MediaRejected;
use Illuminate\Http\UploadedFile;

/**
 * Contrôles d'un fichier reçu (docs/security.md §7). Rien de ce que fournit le client n'est cru :
 * - le MIME est DÉTECTÉ par les magic bytes (finfo) et doit figurer dans la liste blanche ;
 * - l'extension du nom d'origine doit être cohérente avec ce MIME détecté ;
 * - la taille est bornée par type, les dimensions d'image aussi (bombes de décompression) ;
 * - le nom d'origine est nettoyé et ne sert qu'à l'affichage (jamais au stockage).
 */
final class MediaFileInspector
{
    public function inspect(UploadedFile $file): InspectedFile
    {
        $path = $file->getRealPath();
        if ($path === false || ! is_file($path) || ! $file->isValid()) {
            throw MediaRejected::unsupportedFormat();
        }

        $size = (int) filesize($path);
        if ($size === 0) {
            throw MediaRejected::empty();
        }

        $clientExtension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $mime = $this->detectMime($path, $clientExtension);
        $format = config("media.formats.{$mime}");
        if (! is_array($format)) {
            throw MediaRejected::unsupportedFormat();
        }
        if (! in_array($clientExtension, $format['aliases'], true)) {
            throw MediaRejected::extensionMismatch();
        }

        $type = MediaType::from($format['type']);
        $maxMb = (int) config("media.max_size_mb.{$type->value}");
        if ($size > $maxMb * 1024 * 1024) {
            throw MediaRejected::tooLarge($maxMb);
        }

        [$width, $height] = $type === MediaType::Image ? $this->imageDimensions($path) : [null, null];

        return new InspectedFile(
            localPath: $path,
            displayName: self::sanitizeName($file->getClientOriginalName(), $format['extension']),
            mimeType: $mime,
            type: $type,
            extension: $format['extension'],
            sizeBytes: $size,
            checksum: hash_file('sha256', $path),
            width: $width,
            height: $height,
        );
    }

    /** MIME réel. Un .m4a est un conteneur MP4 : finfo peut le décrire comme « video/mp4 ». */
    private function detectMime(string $path, string $clientExtension): string
    {
        $mime = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));
        if ($mime === 'video/mp4' && $clientExtension === 'm4a') {
            return 'audio/mp4';
        }

        return $mime;
    }

    /** @return array{0: int, 1: int} */
    private function imageDimensions(string $path): array
    {
        $info = @getimagesize($path);
        if ($info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            throw MediaRejected::unreadableImage();
        }
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        $maxSide = (int) config('media.image.max_side_px');
        if ($width > $maxSide || $height > $maxSide || $width * $height > (int) config('media.image.max_pixels')) {
            throw MediaRejected::dimensions($maxSide);
        }

        return [$width, $height];
    }

    /**
     * Nom d'affichage sûr : sans chemin, sans caractères de contrôle ni d'inversion bidirectionnelle
     * (U+202E…), longueur bornée, extension normalisée sur le format détecté.
     */
    public static function sanitizeName(string $name, string $extension): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $name);
        $name = (string) preg_replace('/[<>:"|?*]/', '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .\t");

        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = trim(mb_substr($base, 0, 180), ' .');
        if ($base === '') {
            $base = 'fichier';
        }

        return "{$base}.{$extension}";
    }
}
