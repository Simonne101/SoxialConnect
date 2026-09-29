<?php

namespace App\Domain\Video\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum ClipSource: string
{
    case Ai = 'ai';
    case Manual = 'manual';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
