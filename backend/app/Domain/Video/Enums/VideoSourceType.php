<?php

namespace App\Domain\Video\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum VideoSourceType: string
{
    case Upload = 'upload';
    case Url = 'url';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
