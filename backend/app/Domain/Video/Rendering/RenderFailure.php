<?php

namespace App\Domain\Video\Rendering;

use RuntimeException;

/**
 * Échec normalisé d'un rendu : code stable + message lisible par l'utilisateur. Le message ne
 * contient jamais de chemin de fichier ni de sortie brute de FFmpeg (journalisée côté serveur).
 */
final class RenderFailure extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function cancelled(): self
    {
        return new self('CANCELLED', 'Le rendu a été annulé.');
    }
}
