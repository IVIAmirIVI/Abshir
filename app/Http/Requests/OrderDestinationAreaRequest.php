<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderDestinationAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delivery_pricing.apply');
    }

    public function rules(): array
    {
        return ['destination_area_id' => ['required', 'integer', 'exists:areas,id']];
    }
}
