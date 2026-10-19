<?php

namespace App\Domain\Video\Enums;

/** Voie par laquelle le fichier rendable a été (ou sera) obtenu (ADR-049). */
enum AcquisitionMethod: string
{
    /** Export officiel Google (Data Portability API), vidéos du propriétaire uniquement. */
    case DataPortability = 'data_portability';
    /** Fournisseur externe autorisé (sous contrat), aucun n'est branché à ce jour. */
    case External = 'external';
    /** Fichier choisi par l'utilisateur dans sa médiathèque (finalisation). */
    case Import = 'import';
    /**
     * Fichier déjà présent dans la médiathèque de l'utilisateur et déjà rattaché à CETTE vidéo YouTube
     * par un autre de ses projets : réutilisé sans copie ni import (LibraryVideoSourceProvider).
     */
    case Library = 'library';
    /**
     * Vidéo YouTube publique ou non répertoriée récupérée par yt-dlp, après attestation de droits par
     * l'utilisateur (ADR-059) ; désactivé par défaut (video.sources.yt_dlp.enabled).
     */
    case YtDlp = 'yt_dlp';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
