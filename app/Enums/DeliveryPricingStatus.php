<?php

namespace App\Enums;

enum DeliveryPricingStatus: string
{
    case Priced = 'PRICED';
    case PendingOverrideApproval = 'PENDING_OVERRIDE_APPROVAL';
}
