<?php

namespace App\Domain\Content\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum VariantStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
