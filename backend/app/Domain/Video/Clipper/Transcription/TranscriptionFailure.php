<?php

namespace App\Domain\Video\Clipper\Transcription;

use RuntimeException;

/** Échec de transcription (code stable, message affichable sans chemin ni secret). */
final class TranscriptionFailure extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
