<?php

namespace App\Domain\Media\Security;

use App\Domain\Media\Enums\ScanStatus;

/** Résultat d'analyse. « notScanned » : aucun moteur n'a examiné le fichier (jamais présenté comme sain). */
final class ScanResult
{
    private function __construct(
        public readonly ScanStatus $status,
        public readonly ?string $signature = null,
    ) {}

    public static function clean(): self
    {
        return new self(ScanStatus::Clean);
    }

    public static function infected(string $signature): self
    {
        return new self(ScanStatus::Infected, $signature);
    }

    public static function error(): self
    {
        return new self(ScanStatus::Error);
    }

    /** Aucun moteur disponible : le média reste « pending » (non analysé). */
    public static function notScanned(): self
    {
        return new self(ScanStatus::Pending);
    }

    public function wasScanned(): bool
    {
        return $this->status !== ScanStatus::Pending;
    }
}
