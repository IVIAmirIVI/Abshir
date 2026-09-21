<?php

namespace App\Enums;

enum CaptainRatingSource: string
{
    case WhatsAppManual = 'WHATSAPP_MANUAL';
    case CustomerApp = 'CUSTOMER_APP';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
