<?php

namespace App\Enums;

enum OrderCancellationReason: string
{
    case CustomerCancelled = 'CUSTOMER_CANCELLED';
    case CustomerUnreachable = 'CUSTOMER_UNREACHABLE';
    case CustomerRefused = 'CUSTOMER_REFUSED';
    case ShopUnavailable = 'SHOP_UNAVAILABLE';
    case Operational = 'OPERATIONAL';
    case Other = 'OTHER';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
