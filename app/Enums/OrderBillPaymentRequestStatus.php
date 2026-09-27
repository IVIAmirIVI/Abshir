<?php

namespace App\Enums;

enum OrderBillPaymentRequestStatus: string
{
    case Requested = 'REQUESTED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
}
