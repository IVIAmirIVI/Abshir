<?php

namespace App\Http\Requests;

use App\Enums\PriceObservationSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductPriceObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('product_prices.create');
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'shop_id' => ['required', 'integer', Rule::exists('shops', 'id')],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')],
            'price' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999999999.9999'],
            'observed_at' => ['required', 'date', 'before_or_equal:now'],
            'source' => ['required', Rule::enum(PriceObservationSource::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
