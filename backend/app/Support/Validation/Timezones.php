<?php

namespace App\Support\Validation;

/**
 * Fuseaux horaires IANA acceptés par l'API (profil, publications) : identifiants canoniques
 * ET alias officiels (« America/Montreal », « Asia/Calcutta »…) que renvoient certains
 * navigateurs via Intl. Validation stricte : correspondance exacte avec la base IANA de PHP.
 */
final class Timezones
{
    /** @return list<string> */
    public static function identifiers(): array
    {
        return \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC);
    }
}
