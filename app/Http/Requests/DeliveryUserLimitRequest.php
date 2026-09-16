<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeliveryUserLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delivery_discount_limits.manage');
    }

    public function rules(): array
    {
        return ['user_id' => ['required', 'integer', 'exists:users,id'], 'max_discount_percent' => ['required', 'decimal:0,4', 'between:0,100']];
    }
}
