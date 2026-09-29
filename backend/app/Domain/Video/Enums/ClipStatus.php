<?php

namespace App\Domain\Video\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum ClipStatus: string
{
    case Suggested = 'suggested';
    case Edited = 'edited';
    case Rendering = 'rendering';
    case Rendered = 'rendered';
    case Failed = 'failed';
    case Discarded = 'discarded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
