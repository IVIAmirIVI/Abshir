<?php

namespace App\Rules;

use App\Services\PhoneNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MobilePhone implements ValidationRule
{
    public function __construct(private readonly bool $mobileOnly = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('order_ux.mobile_format'));

            return;
        }
        $digits = app(PhoneNormalizer::class)->normalize($value);
        $mobile = preg_match('/^(?:09|9639|009639)/', $digits) || preg_match('/^\s*[٠۰][٩۹]/u', $value);
        if (($this->mobileOnly || $mobile)
            && ! preg_match('/^09[0-9]{8}$/D', $value)) {
            $fail(__('order_ux.mobile_format'));
        }
    }
}
