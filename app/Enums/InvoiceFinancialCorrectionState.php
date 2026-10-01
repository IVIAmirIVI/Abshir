<?php

namespace App\Enums;

enum InvoiceFinancialCorrectionState: string
{
    case Requested = 'REQUESTED';
    case Prepared = 'PREPARED';
    case ReadyToFinalize = 'READY_TO_FINALIZE';
    case Completed = 'COMPLETED';
    case Abandoned = 'ABANDONED';
}
