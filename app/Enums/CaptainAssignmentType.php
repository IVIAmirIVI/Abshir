<?php

namespace App\Enums;

enum CaptainAssignmentType: string
{
    case Temporary = 'TEMPORARY';
    case PermanentTransfer = 'PERMANENT_TRANSFER';

    public function label(): string
    {
        return match ($this) {
            self::Temporary => 'Temporary assignment',
            self::PermanentTransfer => 'Permanent transfer',
        };
    }
}
