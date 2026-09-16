<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeliveryFeeOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delivery_pricing.override');
    }

    public function rules(): array
    {
        return ['from_area_id' => ['required', 'integer', 'exists:areas,id'], 'requested_unit_fee' => ['required', 'decimal:0,4', 'gt:0'], 'reason' => ['required', 'string', 'max:2000']];
    }
}
