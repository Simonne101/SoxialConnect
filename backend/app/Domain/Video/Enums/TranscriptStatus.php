<?php

namespace App\Domain\Video\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum TranscriptStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
