<?php

namespace App\Enums;

enum ShopBusinessClassification: string
{
    case Restaurant = 'RESTAURANT';
    case Shop = 'SHOP';

    public function label(): string
    {
        return match ($this) {
            self::Restaurant => 'Restaurant',
            self::Shop => 'Shop',
        };
    }
}
