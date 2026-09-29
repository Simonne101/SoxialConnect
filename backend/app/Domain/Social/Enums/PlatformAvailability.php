<?php

namespace App\Domain\Social\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum PlatformAvailability: string
{
    case Available = 'available';
    case ComingSoon = 'coming_soon';
    case Disabled = 'disabled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
