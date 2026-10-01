<?php

namespace App\Domain\Publishing\Enums;

// Valeurs alignées sur la contrainte CHECK publication_attempts_trigger_check.
enum AttemptTrigger: string
{
    /** Heure prévue atteinte (planificateur). */
    case Schedule = 'schedule';
    /** « Publier maintenant ». */
    case Manual = 'manual';
    /** « Réessayer » après un échec. */
    case Retry = 'retry';
    /** Nouvelle tentative automatique après une erreur temporaire du réseau. */
    case AutoRetry = 'auto_retry';
    /** Job perdu (file vidée, worker arrêté) renvoyé par le planificateur. */
    case Recovery = 'recovery';
}
