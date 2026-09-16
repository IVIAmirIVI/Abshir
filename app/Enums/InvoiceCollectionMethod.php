<?php

namespace App\Enums;

enum InvoiceCollectionMethod: string
{
    case Cash = 'CASH';
    case ShamCash = 'SHAM_CASH';
}
