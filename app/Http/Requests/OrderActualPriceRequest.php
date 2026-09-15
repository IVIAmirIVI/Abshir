<?php

namespace App\Http\Requests;

use App\Enums\ActualPriceSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderActualPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('orders.pricing.update');
    }

    public function rules(): array
    {
        return ['actual_unit_price' => ['required', 'decimal:0,4', 'gt:0'], 'actual_currency_id' => ['required', 'integer', 'exists:currencies,id'], 'actual_price_source' => ['required', Rule::enum(ActualPriceSource::class)]];
    }
}
