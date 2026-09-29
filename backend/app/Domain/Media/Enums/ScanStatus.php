<?php

namespace App\Domain\Media\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum ScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
    case Error = 'error';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
