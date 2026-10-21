<?php

namespace App\Domain\Video\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
/** Transition à l'entrée de la scène. */
enum SceneTransition: string
{
    case None = 'none';
    case Fade = 'fade';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
