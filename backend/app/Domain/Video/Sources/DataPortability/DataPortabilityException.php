<?php

namespace App\Domain\Video\Sources\DataPortability;

use RuntimeException;

/** Erreur normalisée de la Data Portability API : jamais le message ni le corps bruts de Google. */
final class DataPortabilityException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $transient = false,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }
}
