<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductGroupMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('product') && $this->user()->can('product_groups.assign');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'product_group_id' => ['required', 'integer', Rule::exists('product_groups', 'id')],
            'joined_at' => ['required', 'date'],
            'left_at' => ['nullable', 'date', 'after:joined_at'],
            'created_by_user_id' => ['prohibited'],
            'ended_by_user_id' => ['prohibited'],
        ];
    }
}
