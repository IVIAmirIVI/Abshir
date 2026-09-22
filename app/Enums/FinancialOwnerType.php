<?php

namespace App\Enums;

enum FinancialOwnerType: string
{
    case Captain = 'CAPTAIN';
    case Shop = 'SHOP';
    case Company = 'COMPANY';
    case Branch = 'BRANCH';
}
