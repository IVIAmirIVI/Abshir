<?php

namespace App\Enums;

enum ActualPriceSource: string
{
    case ShopConfirmed = 'SHOP_CONFIRMED';
    case PurchaseReceipt = 'PURCHASE_RECEIPT';
    case ManualVerified = 'MANUAL_VERIFIED';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
