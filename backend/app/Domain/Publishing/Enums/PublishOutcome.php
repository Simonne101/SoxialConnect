<?php

namespace App\Domain\Publishing\Enums;

/** Résultat d'un appel d'adaptateur (contrat SocialPublisher). */
enum PublishOutcome: string
{
    case Success = 'success';
    /** Erreur passagère (limite de débit, indisponibilité) : une nouvelle tentative a un sens. */
    case TemporaryFailure = 'temporary_failure';
    /** Refus définitif (contenu refusé, permission manquante) : relancer à l'identique échouera. */
    case PermanentFailure = 'permanent_failure';
    /** Aucune intégration active pour ce réseau : rien n'a été envoyé. */
    case NotConfigured = 'not_configured';
    /**
     * Contenu entièrement transmis, traitement en cours chez le réseau : le résultat final sera
     * obtenu par suivi (TracksPublicationStatus + ReconcilePublicationJob). Jamais relancé.
     */
    case Processing = 'processing';
    /** Impossible de conclure (réponse inattendue) : échec explicite, relance sur confirmation. */
    case Unknown = 'unknown';
}
