<?php

namespace App\Services;

class PhoneNormalizer
{
    public function normalize(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }
}
