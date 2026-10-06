<?php

namespace App\Domain\Video\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
/** Cadrage du visuel dans le format du projet : remplir (recadrage) ou contenir (bandes). */
enum SceneFit: string
{
    case Cover = 'cover';
    case Contain = 'contain';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
