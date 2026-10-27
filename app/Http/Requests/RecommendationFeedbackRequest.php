<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecommendationFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'relevance' => ['required', Rule::in(['1', '0'])],
            'rejection_reason' => ['nullable', Rule::requiredIf($this->input('relevance') === '0'), Rule::in(['style', 'price', 'material', 'dimensions', 'room_fit', 'other'])],
        ];
    }
}
