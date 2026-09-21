<?php

namespace App\Http\Requests;

use App\Enums\OrderEntryMode;
use App\Enums\OrderSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('orders.create');
    }

    public function rules(): array
    {
        return ['branch_id' => ['required', 'integer', 'exists:branches,id'], 'customer_id' => ['required', 'integer', 'exists:customers,id'], 'customer_phone_id' => ['required', 'integer', 'exists:customer_phones,id'], 'customer_address_id' => ['required', 'integer', 'exists:customer_addresses,id'], 'source' => ['required', Rule::in(array_map(fn (OrderSource $source) => $source->value, OrderSource::staffChoices()))], 'entry_mode' => ['required', Rule::enum(OrderEntryMode::class)], 'ordered_at' => ['nullable', 'required_if:entry_mode,'.OrderEntryMode::HistoricalManual->value, 'date', 'before_or_equal:now'], 'notes' => ['nullable', 'string', 'max:3000']];
    }
}
