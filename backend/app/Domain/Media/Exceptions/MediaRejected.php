<?php

namespace App\Domain\Media\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Fichier refusé par le pipeline d'upload (format, taille, dimensions, antivirus) : 422 sur le champ « file ». */
final class MediaRejected extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }

    public static function unsupportedFormat(): self
    {
        return new self('Format non accepté. Formats possibles : JPG, PNG, WebP, GIF, MP4, MOV, WebM, MP3, M4A, WAV, PDF.', 'unsupported_format');
    }

    public static function extensionMismatch(): self
    {
        return new self('Le contenu du fichier ne correspond pas à son extension.', 'extension_mismatch');
    }

    public static function tooLarge(int $maxMb): self
    {
        return new self("Fichier trop volumineux pour ce type de média (maximum {$maxMb} Mo).", 'too_large');
    }

    public static function empty(): self
    {
        return new self('Le fichier est vide.', 'empty');
    }

    public static function unreadableImage(): self
    {
        return new self('Cette image est illisible ou endommagée.', 'unreadable_image');
    }

    public static function dimensions(int $maxSide): self
    {
        return new self("Image trop grande : {$maxSide} px de côté au maximum.", 'dimensions');
    }

    public static function infected(): self
    {
        return new self('Ce fichier a été refusé par l\'analyse antivirus.', 'infected');
    }

    public static function scanFailed(): self
    {
        return new self('L\'analyse de sécurité du fichier a échoué. Réessayez plus tard.', 'scan_error');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['file' => [$this->getMessage()]],
            'meta' => ['reason' => $this->reason],
        ], 422);
    }
}
