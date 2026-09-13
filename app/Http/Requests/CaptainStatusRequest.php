<?php

namespace App\Http\Requests;

use App\Enums\CaptainOperationalStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\RequiredIf;

class CaptainStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $captain = $this->route('captain');

        return $captain && $this->user()->can('captains.status.update') && $this->user()->canAccessCaptain($captain);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'operational_status' => ['required', Rule::enum(CaptainOperationalStatus::class)],
            'reason' => [new RequiredIf($this->input('operational_status') === CaptainOperationalStatus::Suspended->value), 'nullable', 'string', 'max:5000'],
        ];
    }
}
