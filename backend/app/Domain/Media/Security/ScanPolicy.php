<?php

namespace App\Domain\Media\Security;

use App\Domain\Media\Enums\ScanStatus;

/**
 * Quels résultats d'analyse rendent un média utilisable (servi, publiable) — docs/database.md §3.4 :
 * - « clean » : toujours ;
 * - « pending » (non analysé) : uniquement si aucun moteur n'est configuré ET que l'analyse n'est pas
 *   obligatoire (développement local). Avec un moteur, un média en attente reste en quarantaine.
 * - « infected » / « error » : jamais.
 */
final class ScanPolicy
{
    public function __construct(private readonly MediaSecurityScanner $scanner) {}

    public function isRequired(): bool
    {
        return (bool) config('media.scan.required');
    }

    public function scannerAvailable(): bool
    {
        return $this->scanner->isAvailable();
    }

    /** @return list<string> */
    public function usableStatuses(): array
    {
        $statuses = [ScanStatus::Clean->value];
        if (! $this->isRequired() && ! $this->scanner->isAvailable()) {
            $statuses[] = ScanStatus::Pending->value;
        }

        return $statuses;
    }
}
