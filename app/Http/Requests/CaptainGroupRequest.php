<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CaptainGroupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can($this->route('captain_group') ? 'captain_groups.update' : 'captain_groups.create');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('captain_groups', 'code')->ignore($this->route('captain_group'))],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['prohibited'],
        ];
    }
}
