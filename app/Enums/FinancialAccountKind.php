<?php

namespace App\Enums;

enum FinancialAccountKind: string
{
    case CaptainDeposit = 'CAPTAIN_DEPOSIT';
    case CaptainCompanyReceivable = 'CAPTAIN_COMPANY_RECEIVABLE';
    case CaptainCashCustody = 'CAPTAIN_CASH_CUSTODY';
}
