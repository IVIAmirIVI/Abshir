<?php

namespace App\Http\Requests;

use App\Enums\OrderCaptainRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderCaptainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('orders.captains.assign');
    }

    public function rules(): array
    {
        return [
            'captain_id' => ['required', 'integer', 'exists:captains,id'],
            'assignment_role' => ['required', Rule::enum(OrderCaptainRole::class)],
        ];
    }
}
