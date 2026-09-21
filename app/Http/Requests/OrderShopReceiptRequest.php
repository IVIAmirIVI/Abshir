<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderShopReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('order_shop_receipts.upload');
    }

    public function rules(): array
    {
        return ['receipt' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'], 'note' => ['nullable', 'string', 'max:1000']];
    }
}
