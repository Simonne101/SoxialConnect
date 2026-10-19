<?php

namespace App\Domain\Video\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum ClipSource: string
{
    case Ai = 'ai';
    case Manual = 'manual';
    /** Proposé par l'analyse locale (heuristique, sans IA), ADR-047. */
    case Auto = 'auto';
    /** Valeur autorisée en base (migration 2026_10_07_195617) mais plus produite : ADR-050 remplacée par l'ADR-051. */
    case External = 'external';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
