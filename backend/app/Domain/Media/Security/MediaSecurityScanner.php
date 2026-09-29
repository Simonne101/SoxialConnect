<?php

namespace App\Domain\Media\Security;

/**
 * Analyse antivirus d'un fichier en quarantaine (docs/security.md §7) :
 * Upload → Quarantaine → Analyse → Validé → Disponible.
 *
 * Implémentations : NullMediaScanner (aucun moteur installé, résultat « non analysé ») ;
 * une implémentation ClamAV (clamd) sera liée dans AppServiceProvider selon config('media.scan.driver').
 */
interface MediaSecurityScanner
{
    /** Vrai si un moteur d'analyse réel est configuré et joignable. */
    public function isAvailable(): bool;

    /** Analyse le fichier local indiqué (chemin temporaire en quarantaine). */
    public function scan(string $localPath): ScanResult;
}
