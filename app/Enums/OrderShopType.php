<?php

namespace App\Enums;

enum OrderShopType: string
{
    case Registered = 'REGISTERED';
    case OneTime = 'ONE_TIME';

    public function label(): string
    {
        return $this === self::Registered ? 'Registered Shop' : 'One-time Shop';
    }
}
