<?php

namespace App\Domain\Social\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum SocialAccountStatus: string
{
    case Connected = 'connected';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Error = 'error';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
