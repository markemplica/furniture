<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportFiltersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'report' => ['nullable', Rule::in(['reservations', 'recommendations', 'customers', 'furniture'])],
            'format' => ['nullable', Rule::in(['csv', 'pdf'])],
            'q' => ['nullable', 'string', 'max:200'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }
}
