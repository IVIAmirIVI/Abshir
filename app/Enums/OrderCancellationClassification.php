<?php

namespace App\Enums;

enum OrderCancellationClassification: string
{
    case BeforePurchase = 'BEFORE_PURCHASE';
    case AfterPurchase = 'AFTER_PURCHASE';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }

    public function requiresFinancialResolution(): bool
    {
        return $this === self::AfterPurchase;
    }
}
