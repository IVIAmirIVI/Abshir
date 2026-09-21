<?php

namespace App\Http\Requests;

use App\Services\OrderShopProgressService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderShopProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('orders.update');
    }

    public function rules(): array
    {
        return ['marker' => ['required', Rule::in(OrderShopProgressService::MARKERS)]];
    }
}
