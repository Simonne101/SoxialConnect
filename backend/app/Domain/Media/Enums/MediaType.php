<?php

namespace App\Domain\Media\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum MediaType: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
