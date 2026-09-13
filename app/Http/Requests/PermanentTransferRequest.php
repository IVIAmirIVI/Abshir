<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PermanentTransferRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $captain = $this->route('captain');

        return $captain && $this->user()->can('captain_branches.transfer') && $this->user()->canAccessCaptain($captain);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')],
            'starts_at' => ['required', 'date', 'before_or_equal:now'],
            'reason' => ['required', 'string', 'max:2000'],
            'captain_id' => ['prohibited'],
            'assignment_type' => ['prohibited'],
            'from_branch_id' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
        ];
    }
}
