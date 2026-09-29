<?php

namespace App\Domain\Media\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum MediaStatus: string
{
    case Uploading = 'uploading';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Deleted = 'deleted';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
