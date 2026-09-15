<?php

namespace App\Enums;

enum OrderPricingStatus: string
{
    case Estimated = 'ESTIMATED';
    case PartiallyConfirmed = 'PARTIALLY_CONFIRMED';
    case Confirmed = 'CONFIRMED';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
