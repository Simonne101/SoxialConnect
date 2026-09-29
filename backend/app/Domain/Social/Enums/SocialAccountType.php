<?php

namespace App\Domain\Social\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum SocialAccountType: string
{
    case Profile = 'profile';
    case Page = 'page';
    case Business = 'business';
    case Creator = 'creator';
    case Channel = 'channel';
    case Organization = 'organization';
    case BoardOwner = 'board_owner';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
