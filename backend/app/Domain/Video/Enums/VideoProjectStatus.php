<?php

namespace App\Domain\Video\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
// Composition : draft → rendering → ready | failed. Clipping : pending → … → ready | failed | cancelled.
enum VideoProjectStatus: string
{
    case Draft = 'draft';
    case Rendering = 'rendering';
    case Pending = 'pending';
    case Importing = 'importing';
    case Analyzing = 'analyzing';
    case Transcribing = 'transcribing';
    case Detecting = 'detecting';
    case Ready = 'ready';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
