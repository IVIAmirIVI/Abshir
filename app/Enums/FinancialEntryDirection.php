<?php

namespace App\Enums;

enum FinancialEntryDirection: string
{
    case Increase = 'INCREASE';
    case Decrease = 'DECREASE';

    public function opposite(): self
    {
        return $this === self::Increase ? self::Decrease : self::Increase;
    }
}
