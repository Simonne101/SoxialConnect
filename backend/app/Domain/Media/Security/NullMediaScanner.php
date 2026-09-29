<?php

namespace App\Domain\Media\Security;

/**
 * Aucun antivirus installé (ClamAV absent en local). N'analyse RIEN et ne prétend jamais qu'un
 * fichier est sain : le média reste « non analysé » (scan_status = pending).
 * Si config('media.scan.required') est vrai, les imports sont refusés (échec fermé).
 */
final class NullMediaScanner implements MediaSecurityScanner
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function scan(string $localPath): ScanResult
    {
        return ScanResult::notScanned();
    }
}
