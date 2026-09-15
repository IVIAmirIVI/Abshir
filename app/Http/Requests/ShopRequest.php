<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        $shop = $this->route('shop');

        return $this->user()->can($shop ? 'shops.update' : 'shops.create')
            && (! $shop || $this->user()->canAccessShop($shop));
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'shop_type_id' => ['nullable', 'integer', Rule::exists('shop_types', 'id')],
            'area_id' => ['nullable', 'integer', Rule::exists('areas', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:64', Rule::unique('shops')->ignore($this->route('shop'))],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_mobile_visible' => ['required', 'boolean'],
            'accepts_online_orders' => ['required', 'boolean'],
            'is_active' => ['prohibited'],
        ];
    }
}
