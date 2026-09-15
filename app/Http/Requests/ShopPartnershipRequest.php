<?php

namespace App\Http\Requests;

use App\Enums\ShopPartnershipStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShopPartnershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        $shop = $this->route('shop');

        return $shop && $this->user()->can('shop_partnerships.create') && $this->user()->canAccessShop($shop);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'status' => ['required', Rule::enum(ShopPartnershipStatus::class), Rule::notIn([ShopPartnershipStatus::Ended->value])],
            'mobile_enabled' => ['required', 'boolean'],
            'online_orders_enabled' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
