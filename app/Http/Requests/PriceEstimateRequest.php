<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PriceEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('product_prices.estimate');
    }

    public function rules(): array
    {
        return [
            'preview' => ['nullable', 'boolean'],
            'product_id' => ['required_if:preview,1', 'nullable', 'integer', Rule::exists('products', 'id')],
            'unit_id' => ['required_if:preview,1', 'nullable', 'integer', Rule::exists('units', 'id')],
            'currency_id' => ['required_if:preview,1', 'nullable', 'integer', Rule::exists('currencies', 'id')],
            'reference_at' => ['nullable', 'date'],
        ];
    }
}
