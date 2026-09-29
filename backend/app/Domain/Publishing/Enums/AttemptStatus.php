<?php

namespace App\Domain\Publishing\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum AttemptStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
