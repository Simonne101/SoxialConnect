<?php

namespace App\Domain\Video\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
/** Type de projet vidéo : composition par scènes (Video Studio) ou découpage d'une vidéo longue (clipping). */
enum VideoProjectKind: string
{
    case Composition = 'composition';
    case Clipping = 'clipping';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
