<?php

namespace App\Enums;

enum OrderPricingClass: string
{
    case SingleRestaurant = 'SINGLE_RESTAURANT';
    case SingleShop = 'SINGLE_SHOP';
    case MultiShop = 'MULTI_SHOP';

    public function label(): string
    {
        return match ($this) {
            self::SingleRestaurant => 'Single Restaurant',
            self::SingleShop => 'Single Shop',
            self::MultiShop => 'Multi Shop',
        };
    }

    public static function fromShopClassification(ShopBusinessClassification $classification): self
    {
        return $classification === ShopBusinessClassification::Restaurant
            ? self::SingleRestaurant
            : self::SingleShop;
    }
}
