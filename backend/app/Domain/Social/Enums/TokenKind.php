<?php

namespace App\Domain\Social\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum TokenKind: string
{
    case User = 'user';
    case Page = 'page';
    case App = 'app';
    /** Autorisation Google Data Portability (export officiel des vidéos de la chaîne, ADR-054). */
    case Portability = 'portability';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
