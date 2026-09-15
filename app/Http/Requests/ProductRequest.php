<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('product') ? 'products.update' : 'products.create');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'default_unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('products')->ignore($this->route('product'))],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products')->ignore($this->route('product'))],
            'description' => ['nullable', 'string', 'max:10000'],
            'is_active' => ['prohibited'],
        ];
    }
}
