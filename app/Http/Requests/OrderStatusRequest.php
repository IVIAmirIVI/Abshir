<?php

namespace App\Http\Requests;

use App\Enums\OrderOperationalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('orders.status.update');
    }

    public function rules(): array
    {
        return ['operational_status' => ['required', Rule::enum(OrderOperationalStatus::class)], 'reason' => ['nullable', 'string', 'max:1000']];
    }
}
