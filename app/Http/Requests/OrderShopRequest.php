<?php

namespace App\Http\Requests;

use App\Enums\OrderShopType;
use App\Enums\ShopBusinessClassification;
use App\Rules\MobilePhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('orders.update');
    }

    public function rules(): array
    {
        return [
            'shop_type' => ['required', Rule::enum(OrderShopType::class)],
            'business_classification' => ['nullable', Rule::enum(ShopBusinessClassification::class)],
            'shop_id' => ['nullable', 'required_if:shop_type,REGISTERED', 'prohibited_if:shop_type,ONE_TIME', 'integer', 'exists:shops,id'],
            'origin_area_id' => ['nullable', 'required_if:shop_type,ONE_TIME', 'prohibited_if:shop_type,REGISTERED', 'integer', 'exists:areas,id'],
            'shop_name' => ['nullable', 'required_if:shop_type,ONE_TIME', 'prohibited_if:shop_type,REGISTERED', 'string', 'max:255'],
            'shop_phone' => ['nullable', 'prohibited_if:shop_type,REGISTERED', 'string', 'max:50', new MobilePhone],
            'shop_address' => ['nullable', 'prohibited_if:shop_type,REGISTERED', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return [
            'shop_type' => __('orders.ui_shop_source'),
            'shop_id' => __('orders.ui_registered_shop'),
            'origin_area_id' => __('orders.ui_shop_area'),
            'business_classification' => __('orders.ui_shop_classification'),
            'shop_name' => __('orders.ui_one_shop_name'),
            'shop_phone' => __('orders.ui_one_phone'),
            'shop_address' => __('orders.ui_one_address'),
            'notes' => __('common.fields.notes'),
        ];
    }
}
