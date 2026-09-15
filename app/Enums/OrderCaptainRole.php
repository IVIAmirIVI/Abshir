<?php

namespace App\Enums;

enum OrderCaptainRole: string
{
    case PrimaryCollection = 'PRIMARY_COLLECTION';
    case Support = 'SUPPORT';

    public function label(): string
    {
        return $this === self::PrimaryCollection ? 'Primary collection' : 'Support';
    }
}
