<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShopProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $shop = $this->route('shop');

        return $shop && $this->user()->can($this->route('shop_product') ? 'shop_products.update' : 'shop_products.create')
            && $this->user()->canAccessShop($shop);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'currency_id' => ['nullable', 'required_with:listed_price', 'integer', Rule::exists('currencies', 'id')],
            'listed_price' => ['nullable', 'decimal:0,4', 'min:0'],
            'is_available' => ['required', 'boolean'],
            'is_featured' => ['required', 'boolean'],
            'is_active' => ['prohibited'],
        ];
    }
}
