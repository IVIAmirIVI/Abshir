<?php

namespace App\Enums;

enum ShopPartnershipStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Ended = 'ENDED';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Ended => 'Ended',
        };
    }
}
