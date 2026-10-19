<?php

namespace App\Domain\Analytics\Exceptions;

use RuntimeException;

/**
 * Échec de récupération des statistiques. transient : nouvelle tentative différée (limite de débit, délai
 * dépassé, indisponibilité) ; sinon définitif (autorisation retirée…). Le message ne contient jamais de jeton.
 */
final class MetricsFetchFailed extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $transient = false,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
