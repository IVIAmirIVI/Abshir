<?php

namespace App\Enums;

enum PriceObservationSource: string
{
    case Manual = 'MANUAL';
    case ShopConfirmed = 'SHOP_CONFIRMED';
    case PurchaseReceipt = 'PURCHASE_RECEIPT';
    case Imported = 'IMPORTED';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::ShopConfirmed => 'Shop confirmed',
            self::PurchaseReceipt => 'Purchase receipt',
            self::Imported => 'Imported',
        };
    }
}
