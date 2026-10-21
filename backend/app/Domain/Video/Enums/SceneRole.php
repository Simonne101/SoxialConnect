<?php

namespace App\Domain\Video\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
/** Rôle narratif d'une scène (structure de script : accroche, introduction, développement, appel à l'action). */
enum SceneRole: string
{
    case Hook = 'hook';
    case Intro = 'intro';
    case Body = 'body';
    case Cta = 'cta';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
