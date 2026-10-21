<?php

namespace App\Domain\Analytics\Exceptions;

use RuntimeException;

/** Métriques non accessibles pour ce compte ou cette publication (réseau, type de compte, autorisation absente). */
final class MetricsNotSupported extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
