<?php

namespace App\Enums;

enum CaptainOperationalStatus: string
{
    case Available = 'AVAILABLE';
    case Unavailable = 'UNAVAILABLE';
    case OnLeave = 'ON_LEAVE';
    case Suspended = 'SUSPENDED';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Unavailable => 'Unavailable',
            self::OnLeave => 'On leave',
            self::Suspended => 'Suspended',
        };
    }
}
