<?php

namespace App\Domain\Social\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum TokenKind: string
{
    case User = 'user';
    case Page = 'page';
    case App = 'app';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
