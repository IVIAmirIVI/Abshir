<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isSuperAdmin()
            && $this->user()->can($this->route('role') ? 'roles.update' : 'roles.create');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z][A-Z0-9_]*$/', Rule::unique('roles')->ignore($this->route('role'))],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')],
            'is_system' => ['prohibited'],
        ];
    }
}
