<?php

namespace App\Domain\Analytics\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum SnapshotScope: string
{
    case Account = 'account';
    case Post = 'post';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
