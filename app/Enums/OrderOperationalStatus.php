<?php

namespace App\Enums;

enum OrderOperationalStatus: string
{
    case New = 'NEW';
    case Confirmed = 'CONFIRMED';
    case CaptainAssigned = 'CAPTAIN_ASSIGNED';
    case HeadingToShops = 'HEADING_TO_SHOPS';
    case Shopping = 'SHOPPING';
    case HeadingToCustomer = 'HEADING_TO_CUSTOMER';
    case Delivered = 'DELIVERED';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }

    public function next(): ?self
    {
        return match ($this) {
            self::New => self::Confirmed, self::Confirmed => self::CaptainAssigned, self::CaptainAssigned => self::HeadingToShops, self::HeadingToShops => self::Shopping, self::Shopping => self::HeadingToCustomer, self::HeadingToCustomer => self::Delivered, self::Delivered => null
        };
    }
}
