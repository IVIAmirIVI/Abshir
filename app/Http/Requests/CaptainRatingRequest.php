<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CaptainRatingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('captain_ratings.record');
    }

    public function rules(): array
    {
        return ['captain_id' => ['required', 'integer', 'exists:captains,id'], 'rating' => ['required', 'integer', 'between:1,5'], 'source' => ['prohibited'], 'entered_by' => ['prohibited']];
    }
}
