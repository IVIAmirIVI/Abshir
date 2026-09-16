<?php

namespace App\Enums;

enum DeliveryOverrideStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
}
