<?php

namespace App\Enums;

enum OrderSource: string
{
    case WhatsApp = 'WHATSAPP';
    case Phone = 'PHONE';
    case Manual = 'MANUAL';
    case MobileApp = 'MOBILE_APP';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }

    public static function staffChoices(): array
    {
        return [self::WhatsApp, self::Phone, self::Manual];
    }
}
