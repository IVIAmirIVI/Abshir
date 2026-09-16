<?php

namespace App\Http\Requests;

use App\Enums\InvoiceCollectionMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('invoice_collections.create');
    }

    public function rules(): array
    {
        return [
            'collection_method' => ['required', Rule::enum(InvoiceCollectionMethod::class)],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'collected_at' => ['required', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
