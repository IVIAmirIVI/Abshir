<?php

namespace App\Http\Requests;

use App\Enums\OrderPricingClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeliveryPricingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('delivery_pricing_rule') ? 'delivery_pricing_rules.update' : 'delivery_pricing_rules.create');
    }

    public function rules(): array
    {
        return ['from_area_id' => ['required', 'integer', 'exists:areas,id'], 'to_area_id' => ['required', 'integer', 'exists:areas,id'], 'currency_id' => ['required', 'integer', 'exists:currencies,id'], 'order_pricing_class' => ['required', Rule::enum(OrderPricingClass::class)], 'delivery_unit_fee' => ['required', 'decimal:0,4', 'gt:0'], 'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'], 'notes' => ['nullable', 'string', 'max:2000']];
    }
}
