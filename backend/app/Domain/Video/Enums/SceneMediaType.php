<?php

namespace App\Domain\Video\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
/** Type du visuel d'une scène (média de la médiathèque). */
enum SceneMediaType: string
{
    case Image = 'image';
    case Video = 'video';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
