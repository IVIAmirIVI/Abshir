<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('unit') ? 'units.update' : 'units.create');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('units')->ignore($this->route('unit'))],
            'symbol' => ['nullable', 'string', 'max:32'],
            'allows_decimal' => ['required', 'boolean'],
            'is_active' => ['prohibited'],
        ];
    }
}
