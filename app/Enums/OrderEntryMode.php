<?php

namespace App\Enums;

enum OrderEntryMode: string
{
    case Live = 'LIVE';
    case HistoricalManual = 'HISTORICAL_MANUAL';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
