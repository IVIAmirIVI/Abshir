<?php

namespace App\Http\Requests;

use App\Enums\OrderShopType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('orders.update');
    }

    public function rules(): array
    {
        return ['shop_type' => ['required', Rule::enum(OrderShopType::class)], 'shop_id' => ['nullable', 'required_if:shop_type,REGISTERED', 'integer', 'exists:shops,id'], 'shop_name' => ['nullable', 'required_if:shop_type,ONE_TIME', 'string', 'max:255'], 'shop_phone' => ['nullable', 'string', 'max:50'], 'shop_address' => ['nullable', 'string', 'max:500'], 'notes' => ['nullable', 'string', 'max:2000'], 'sort_order' => ['nullable', 'integer', 'min:0']];
    }
}
