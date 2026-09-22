<?php

namespace App\Enums;

enum FinancialTransactionKind: string
{
    case Event = 'EVENT';
    case Transfer = 'TRANSFER';
    case Reversal = 'REVERSAL';
}
