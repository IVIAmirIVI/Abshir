<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->user()?->isSuperAdmin()) {
            $this->merge([
                'role_ids' => $this->input('role_ids', []),
                'branch_ids' => $this->input('branch_ids', []),
            ]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()->can($this->route('user') ? 'users.update' : 'users.create');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user'))],
            'password' => [$this->route('user') ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'role_ids' => [$this->user()->isSuperAdmin() ? 'sometimes' : 'prohibited', 'array'],
            'role_ids.*' => ['integer', 'distinct', Rule::exists('roles', 'id')],
            'branch_ids' => [$this->user()->isSuperAdmin() ? 'sometimes' : 'prohibited', 'array'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists('branches', 'id')],
            'is_active' => ['prohibited'],
        ];
    }
}
