<?php

namespace App\Domain\Video\Enums;

/**
 * Cycle de vie d'une source vidéo du Clipper (ADR-049), distinct du projet : la référence YouTube
 * existe dès la création, le fichier rendable seulement une fois acquis par une voie autorisée.
 */
enum VideoSourceStatus: string
{
    /** Créée, acquisition pas encore tentée. */
    case Pending = 'pending';
    /** Acquisition en cours (vérification de propriété, fichier importé en cours de traitement). */
    case Resolving = 'resolving';
    /** Fournisseur sollicité, résultat attendu (archive en préparation…). */
    case WaitingForProvider = 'waiting_for_provider';
    /** Fichier rendable disponible dans la médiathèque. */
    case Ready = 'ready';
    /** Échec de l'acquisition (erreur normalisée). */
    case Failed = 'failed';
    /** Fichier acquis automatiquement puis purgé (durée de conservation dépassée). */
    case Expired = 'expired';
    /** Aucun fournisseur automatique utilisable : action requise (import autorisé). */
    case Unavailable = 'unavailable';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
