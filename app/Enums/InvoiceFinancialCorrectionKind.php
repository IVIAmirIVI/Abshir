<?php

namespace App\Enums;

enum InvoiceFinancialCorrectionKind: string
{
    case Reprice = 'REPRICE';
    case CancelAndRefund = 'CANCEL_AND_REFUND';
}
