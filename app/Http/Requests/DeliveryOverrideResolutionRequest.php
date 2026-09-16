<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeliveryOverrideResolutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delivery_pricing_overrides.approve');
    }

    public function rules(): array
    {
        return ['decision' => ['required', 'in:approve,reject'], 'resolution_reason' => ['nullable', 'required_if:decision,reject', 'string', 'max:2000']];
    }
}
