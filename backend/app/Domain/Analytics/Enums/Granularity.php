<?php

namespace App\Domain\Analytics\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum Granularity: string
{
    case Day = 'day';
    case Lifetime = 'lifetime';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
