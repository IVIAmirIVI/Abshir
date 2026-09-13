<?php

namespace App\Http\Requests;

use App\Enums\CaptainOperationalStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CaptainRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $captain = $this->route('captain');

        return $this->user()->can($captain ? 'captains.update' : 'captains.create')
            && (! $captain || $this->user()->canAccessCaptain($captain));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => $this->route('captain')
                ? ['prohibited']
                : ['required', 'integer', Rule::exists('users', 'id'), Rule::unique('captains', 'user_id')],
            'home_branch_id' => $this->route('captain')
                ? ['prohibited']
                : ['required', 'integer', Rule::exists('branches', 'id')],
            'operational_status' => $this->route('captain')
                ? ['prohibited']
                : ['sometimes', Rule::enum(CaptainOperationalStatus::class)],
            'employee_code' => ['nullable', 'string', 'max:64', Rule::unique('captains', 'employee_code')->ignore($this->route('captain'))],
            'notes' => ['nullable', 'string', 'max:5000'],
            'hired_at' => $this->route('captain') ? ['prohibited'] : ['required', 'date'],
            'ended_at' => ['prohibited'],
            'status_changed_at' => ['prohibited'],
            'status_changed_by_user_id' => ['prohibited'],
        ];
    }
}
