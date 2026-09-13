<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('branch') ? 'branches.update' : 'branches.create');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('branches')->ignore($this->route('branch'))],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['prohibited'],
        ];
    }
}
