<?php

namespace App\Http\Requests;

use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderCancellationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('orders.cancel');
    }

    public function rules(): array
    {
        return ['classification' => ['required', Rule::enum(OrderCancellationClassification::class)], 'reason' => ['required', Rule::enum(OrderCancellationReason::class)], 'notes' => ['nullable', 'string', 'max:3000']];
    }
}
