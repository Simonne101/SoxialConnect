<?php

namespace App\Domain\Video\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
/** Type d'un élément de timeline (ADR-044). Un élément texte n'a pas d'asset ; les autres en ont un du même type. */
enum TimelineItemType: string
{
    case Video = 'video';
    case Image = 'image';
    case Text = 'text';
    case Audio = 'audio';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function needsAsset(): bool
    {
        return $this !== self::Text;
    }

    /** Extrait de source (source_start_ms, source_duration_ms) possible : vidéo et audio. */
    public function hasSource(): bool
    {
        return $this === self::Video || $this === self::Audio;
    }
}
