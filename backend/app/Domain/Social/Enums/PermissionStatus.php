<?php

namespace App\Domain\Social\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum PermissionStatus: string
{
    case Granted = 'granted';
    case Declined = 'declined';
    case Missing = 'missing';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
