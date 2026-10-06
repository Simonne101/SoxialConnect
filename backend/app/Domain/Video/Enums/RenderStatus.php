<?php

namespace App\Domain\Video\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
/** Statut d'un rendu MP4 (video_render_jobs). */
enum RenderStatus: string
{
    case Queued = 'queued';
    case Rendering = 'rendering';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
