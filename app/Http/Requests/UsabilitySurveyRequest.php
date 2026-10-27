<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UsabilitySurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [];
        for ($question = 1; $question <= 10; $question++) {
            $rules['q'.$question] = ['required', 'integer', 'between:1,5'];
        }

        return $rules;
    }
}
