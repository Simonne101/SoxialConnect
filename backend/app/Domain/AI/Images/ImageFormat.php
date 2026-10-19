<?php

namespace App\Domain\AI\Images;

/**
 * Formats d'image pour les réseaux sociaux (ADR-046). Le fournisseur produit une image carrée,
 * portrait ou paysage (config ai.images.sizes) ; elle est ensuite recadrée au centre au ratio exact.
 */
enum ImageFormat: string
{
    case Square = '1:1';
    case Portrait = '4:5';
    case Story = '9:16';
    case Landscape = '16:9';

    /** Taille demandée au fournisseur, « LARGEURxHAUTEUR ». */
    public function providerSize(): string
    {
        $orientation = match ($this) {
            self::Square => 'square',
            self::Portrait, self::Story => 'portrait',
            self::Landscape => 'landscape',
        };

        return (string) config("ai.images.sizes.{$orientation}");
    }

    /** @return array{0: int, 1: int} ratio largeur:hauteur */
    public function ratio(): array
    {
        return array_map('intval', explode(':', $this->value));
    }

    /**
     * Zone de recadrage centrée au ratio exact dans une image de la taille donnée.
     *
     * @return array{x: int, y: int, width: int, height: int}
     */
    public function cropBox(int $width, int $height): array
    {
        [$rw, $rh] = $this->ratio();
        if ($width * $rh > $height * $rw) {
            $cropHeight = $height;
            $cropWidth = intdiv($height * $rw, $rh);
        } else {
            $cropWidth = $width;
            $cropHeight = intdiv($width * $rh, $rw);
        }

        return ['x' => intdiv($width - $cropWidth, 2), 'y' => intdiv($height - $cropHeight, 2), 'width' => $cropWidth, 'height' => $cropHeight];
    }
}
