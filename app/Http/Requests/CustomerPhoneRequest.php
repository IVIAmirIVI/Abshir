<?php

namespace App\Http\Requests;

use App\Rules\MobilePhone;
use App\Services\PhoneNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerPhoneRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer && $this->user()->can('customers.update') && $this->user()->canAccessCustomer($customer);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:50', new MobilePhone],
            'normalized_phone' => [
                'required', 'string', 'min:6', 'max:32',
                Rule::unique('customer_phones')->where('customer_id', $this->route('customer')->id)->ignore($this->route('phone')),
            ],
            'label' => ['nullable', 'string', 'max:100'],
            'is_primary' => ['sometimes', 'boolean'],
            'duplicate_acknowledged' => ['sometimes', 'boolean'],
            'customer_id' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['normalized_phone' => app(PhoneNormalizer::class)->normalize($this->input('phone'))]);
    }
}
