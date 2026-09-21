<?php

namespace App\Http\Requests;

use App\Enums\ShopBusinessClassification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderShopClassificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('orders.update');
    }

    public function rules(): array
    {
        return ['business_classification' => ['required', Rule::enum(ShopBusinessClassification::class)]];
    }
}
