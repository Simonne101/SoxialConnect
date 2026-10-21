<?php

namespace App\Domain\Video\Enums;

/** Propriété de la vidéo YouTube, vérifiée par l'OAuth YouTube de l'utilisateur (ADR-049). */
enum SourceOwnership: string
{
    /** Non vérifiable (aucune chaîne YouTube connectée, API indisponible). */
    case Unverified = 'unverified';
    /** La vidéo appartient à une chaîne YouTube connectée par l'utilisateur. */
    case Owned = 'owned';
    /** La vidéo appartient à une autre chaîne. */
    case NotOwned = 'not_owned';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
