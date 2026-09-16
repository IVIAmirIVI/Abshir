<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->routeIs('orders.invoices.void') ? 'invoices.void' : 'invoices.replace';

        return $this->user()->can($permission);
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
