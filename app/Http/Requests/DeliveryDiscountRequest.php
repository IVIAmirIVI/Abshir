<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeliveryDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delivery_discounts.apply');
    }

    public function rules(): array
    {
        return ['discount_amount' => ['required', 'decimal:0,4', 'gte:0'], 'reason' => ['nullable', 'required_unless:discount_amount,0', 'string', 'max:2000']];
    }
}
