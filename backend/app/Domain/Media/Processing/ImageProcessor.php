<?php

namespace App\Domain\Media\Processing;

use App\Domain\Media\Exceptions\MediaRejected;

/**
 * Traitement des images avec GD (docs/security.md §7) :
 * - réencodage de l'original (supprime EXIF / GPS, neutralise les fichiers polyglottes),
 *   après application de l'orientation EXIF si l'extension exif est chargée ;
 * - vignette WebP (ou JPEG sans support WebP) de config('media.image.thumbnail_px') px.
 * GIF : l'original est conservé (GD ne gère pas l'animation), seule la vignette est produite.
 * Sans GD : aucun traitement, l'original est conservé tel quel et le média est marqué non réencodé.
 */
final class ImageProcessor
{
    public function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring');
    }

    public function process(string $localPath, string $mimeType): ProcessedImage
    {
        $bytes = (string) file_get_contents($localPath);
        if (! $this->isAvailable()) {
            $info = @getimagesize($localPath) ?: [0, 0];

            return new ProcessedImage($bytes, (int) $info[0], (int) $info[1], reencoded: false);
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw MediaRejected::unreadableImage();
        }

        try {
            if ($mimeType === 'image/jpeg') {
                $image = $this->applyExifOrientation($image, $localPath);
            }
            $width = imagesx($image);
            $height = imagesy($image);

            $isGif = $mimeType === 'image/gif';
            $original = $isGif ? $bytes : $this->encode($image, $mimeType);
            [$thumbnail, $thumbExt, $thumbMime] = $this->thumbnail($image, $width, $height);

            return new ProcessedImage($original, $width, $height, reencoded: ! $isGif, thumbnail: $thumbnail, thumbnailExtension: $thumbExt, thumbnailMime: $thumbMime);
        } finally {
            imagedestroy($image);
        }
    }

    private function encode(\GdImage $image, string $mimeType): string
    {
        $quality = (int) config('media.image.quality', 88);
        ob_start();
        match ($mimeType) {
            'image/png' => $this->withAlpha($image) && imagepng($image, null, 6),
            'image/webp' => $this->withAlpha($image) && imagewebp($image, null, $quality),
            default => imagejpeg($image, null, $quality),
        };

        return (string) ob_get_clean();
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function thumbnail(\GdImage $image, int $width, int $height): array
    {
        $max = (int) config('media.image.thumbnail_px', 480);
        $scale = min(1, $max / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $thumb = imagecreatetruecolor($w, $h);
        $this->withAlpha($thumb);
        imagefill($thumb, 0, 0, imagecolorallocatealpha($thumb, 0, 0, 0, 127));
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $w, $h, $width, $height);

        ob_start();
        $webp = function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false);
        $webp ? imagewebp($thumb, null, 80) : imagejpeg($thumb, null, 82);
        $data = (string) ob_get_clean();
        imagedestroy($thumb);

        return $webp ? [$data, 'webp', 'image/webp'] : [$data, 'jpg', 'image/jpeg'];
    }

    private function withAlpha(\GdImage $image): bool
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return true;
    }

    /** Redresse une photo selon son orientation EXIF avant que le réencodage ne supprime l'EXIF. */
    private function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };
        if ($rotated instanceof \GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }
}
