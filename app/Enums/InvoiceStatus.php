<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'DRAFT';
    case Ready = 'READY';
    case Issued = 'ISSUED';
    case Voided = 'VOIDED';
    case Replaced = 'REPLACED';
}
