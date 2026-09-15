<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderCaptainSwitchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('orders.captains.assign')
            && $this->user()->can('orders.captains.remove');
    }

    public function rules(): array
    {
        return [
            'captain_id' => ['required', 'integer', 'exists:captains,id'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
