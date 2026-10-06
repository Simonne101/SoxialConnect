<?php

namespace App\Domain\Video\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
/** Type d'une piste de timeline (ADR-044) : détermine les éléments qu'elle peut recevoir. */
enum VideoTrackType: string
{
    case Video = 'video';
    case Image = 'image';
    case Text = 'text';
    case Audio = 'audio';
    case Overlay = 'overlay';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Types d'éléments acceptés par une piste de ce type. @return list<TimelineItemType> */
    public function accepts(): array
    {
        return match ($this) {
            self::Video => [TimelineItemType::Video, TimelineItemType::Image],
            self::Image => [TimelineItemType::Image],
            self::Text => [TimelineItemType::Text],
            self::Audio => [TimelineItemType::Audio],
            self::Overlay => [TimelineItemType::Image, TimelineItemType::Video, TimelineItemType::Text],
        };
    }
}
