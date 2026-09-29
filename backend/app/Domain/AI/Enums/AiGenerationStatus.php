<?php

namespace App\Domain\AI\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum AiGenerationStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
