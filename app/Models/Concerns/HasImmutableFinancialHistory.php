<?php

namespace App\Models\Concerns;

use LogicException;

trait HasImmutableFinancialHistory
{
    protected static function bootHasImmutableFinancialHistory(): void
    {
        static::updating(fn () => throw new LogicException('Posted financial history cannot be updated.'));
        static::deleting(fn () => throw new LogicException('Posted financial history cannot be deleted.'));
    }
}
