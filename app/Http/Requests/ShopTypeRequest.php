<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShopTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('shop_type') ? 'shop_types.update' : 'shop_types.create');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('shop_types')->ignore($this->route('shop_type'))],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['prohibited'],
        ];
    }
}
