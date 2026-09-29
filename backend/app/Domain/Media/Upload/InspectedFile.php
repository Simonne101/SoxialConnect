<?php

namespace App\Domain\Media\Upload;

use App\Domain\Media\Enums\MediaType;

/** Fichier reçu, après contrôle : toutes les valeurs viennent du contenu réel, pas du client. */
final class InspectedFile
{
    public function __construct(
        public readonly string $localPath,
        public readonly string $displayName,
        public readonly string $mimeType,
        public readonly MediaType $type,
        public readonly string $extension,
        public readonly int $sizeBytes,
        public readonly string $checksum,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
    ) {}

    public function isImage(): bool
    {
        return $this->type === MediaType::Image;
    }
}
