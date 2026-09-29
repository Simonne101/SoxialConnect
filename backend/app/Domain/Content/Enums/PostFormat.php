<?php

namespace App\Domain\Content\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum PostFormat: string
{
    case Feed = 'feed';
    case Story = 'story';
    case Reel = 'reel';
    case Short = 'short';
    case Video = 'video';
    case Pin = 'pin';
    case Thread = 'thread';
    case Carousel = 'carousel';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
