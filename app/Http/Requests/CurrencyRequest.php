<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('currency') ? 'currencies.update' : 'currencies.create');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/', Rule::unique('currencies')->ignore($this->route('currency'))],
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['nullable', 'string', 'max:16'],
            'decimal_places' => ['required', 'integer', 'between:0,4'],
            'is_active' => ['prohibited'],
        ];
    }
}
