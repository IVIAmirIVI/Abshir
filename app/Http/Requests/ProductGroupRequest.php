<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('product_group') ? 'product_groups.update' : 'product_groups.create');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('product_groups')->ignore($this->route('product_group'))],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['prohibited'],
        ];
    }
}
