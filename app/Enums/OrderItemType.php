<?php

namespace App\Enums;

enum OrderItemType: string
{
    case Catalog = 'CATALOG_ITEM';
    case OneTime = 'ONE_TIME_ITEM';

    public function label(): string
    {
        return $this === self::Catalog ? 'Catalog item' : 'One-time item';
    }
}
