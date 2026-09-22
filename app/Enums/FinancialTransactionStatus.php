<?php

namespace App\Enums;

enum FinancialTransactionStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';
}
