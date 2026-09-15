<?php

namespace App\Enums;

enum OrderItemChangeType: string
{
    case Quantity = 'QUANTITY_CHANGE';
    case Replacement = 'REPLACEMENT';
}
