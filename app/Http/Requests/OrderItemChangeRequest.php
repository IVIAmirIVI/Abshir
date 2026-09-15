<?php

namespace App\Http\Requests;

use App\Enums\OrderItemChangeType;
use App\Enums\OrderItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderItemChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('orders.items.change');
    }

    public function rules(): array
    {
        return ['change_type' => ['required', Rule::enum(OrderItemChangeType::class)], 'quantity' => ['required', 'decimal:0,4', 'gt:0'], 'item_type' => ['nullable', 'required_if:change_type,REPLACEMENT', Rule::enum(OrderItemType::class)], 'product_id' => ['nullable', 'required_if:item_type,CATALOG_ITEM', 'prohibited_if:item_type,ONE_TIME_ITEM', 'integer', 'exists:products,id'], 'item_name' => ['nullable', 'required_if:item_type,ONE_TIME_ITEM', 'string', 'max:255'], 'unit_id' => ['nullable', 'required_if:change_type,REPLACEMENT', 'integer', 'exists:units,id'], 'reason' => ['required', 'string', 'max:2000']];
    }
}
