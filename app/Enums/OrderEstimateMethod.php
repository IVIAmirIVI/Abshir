<?php

namespace App\Enums;

enum OrderEstimateMethod: string
{
    case KnownShopLatest = 'KNOWN_SHOP_LATEST';
    case LatestShopLowest = 'LATEST_SHOP_LOWEST_PRICE';
    case Manual = 'MANUAL_ESTIMATE';
}
