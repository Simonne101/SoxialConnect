<?php

namespace App\Domain\Content\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum PostSource: string
{
    case Manual = 'manual';
    case Ai = 'ai';
    case Clip = 'clip';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
