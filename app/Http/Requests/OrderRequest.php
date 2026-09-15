<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('orders.create');
    }

    public function rules(): array
    {
        return ['branch_id' => ['required', 'integer', 'exists:branches,id'], 'customer_id' => ['required', 'integer', 'exists:customers,id'], 'customer_phone_id' => ['required', 'integer', 'exists:customer_phones,id'], 'customer_address_id' => ['required', 'integer', 'exists:customer_addresses,id'], 'ordered_at' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:3000']];
    }
}
