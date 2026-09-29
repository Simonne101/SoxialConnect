<?php

namespace App\Domain\Users\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
