<?php

namespace App\Http\Requests;

use App\Enums\OrderItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('orders.update');
    }

    public function rules(): array
    {
        return ['item_type' => ['required', Rule::enum(OrderItemType::class)], 'product_id' => ['nullable', 'required_if:item_type,CATALOG_ITEM', 'prohibited_if:item_type,ONE_TIME_ITEM', 'integer', 'exists:products,id'], 'item_name' => ['nullable', 'required_if:item_type,ONE_TIME_ITEM', 'string', 'max:255'], 'unit_id' => ['required', 'integer', 'exists:units,id'], 'quantity' => ['required', 'decimal:0,4', 'gt:0'], 'estimated_currency_id' => ['nullable', 'integer', 'exists:currencies,id'], 'manual_estimated_unit_price' => ['nullable', 'decimal:0,4', 'gt:0'], 'notes' => ['nullable', 'string', 'max:2000']];
    }
}
