<?php

namespace App\Domain\Video\Sources;

use Carbon\CarbonInterface;

/**
 * Résultat d'une étape d'acquisition renvoyé par un VideoSourceProvider (ADR-049). Jamais de succès
 * implicite : seul « acquired » fournit un fichier, que VideoSourceService importe dans la
 * médiathèque (inspection, antivirus) avant de le déclarer prêt ; « reused » désigne un média déjà
 * accepté par la médiathèque, contrôlé à nouveau (propriétaire, état, taille, durée) avant liaison.
 */
final class AcquisitionStep
{
    public const WAITING = 'waiting';

    public const ACQUIRED = 'acquired';

    public const UNAVAILABLE = 'unavailable';

    public const FAILED = 'failed';

    public const REUSED = 'reused';

    /** @param array<string, mixed> $metadata */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $errorCode = null,
        public readonly ?string $message = null,
        public readonly bool $transient = false,
        public readonly int $retryAfterSeconds = 0,
        public readonly ?string $localPath = null,
        public readonly ?string $fileName = null,
        public readonly ?string $mimeType = null,
        public readonly array $metadata = [],
        public readonly ?string $mediaId = null,
        public readonly ?CarbonInterface $purgeAt = null,
    ) {}

    /** @param array<string, mixed> $metadata état propre au fournisseur (identifiant de tâche…) */
    public static function waiting(int $retryAfterSeconds, array $metadata = []): self
    {
        return new self(self::WAITING, retryAfterSeconds: max(1, $retryAfterSeconds), metadata: $metadata);
    }

    /** Fichier local obtenu par le fournisseur ; il sera importé puis supprimé par le service. */
    public static function acquired(string $localPath, string $fileName, string $mimeType): self
    {
        return new self(self::ACQUIRED, localPath: $localPath, fileName: $fileName, mimeType: $mimeType);
    }

    /**
     * Fichier rendable DÉJÀ présent dans la médiathèque de l'utilisateur : lié tel quel (aucune copie,
     * aucun import). purgeAt : conservation héritée de la source d'origine (null = jamais purgé).
     */
    public static function reused(string $mediaId, ?CarbonInterface $purgeAt): self
    {
        return new self(self::REUSED, mediaId: $mediaId, purgeAt: $purgeAt);
    }

    /** Fournisseur inutilisable pour cette source (non configuré, consentement absent…). */
    public static function unavailable(string $errorCode, string $message): self
    {
        return new self(self::UNAVAILABLE, $errorCode, $message);
    }

    public static function failed(string $errorCode, string $message, bool $transient = false, int $retryAfterSeconds = 60): self
    {
        return new self(self::FAILED, $errorCode, $message, $transient, $transient ? max(1, $retryAfterSeconds) : 0);
    }
}
