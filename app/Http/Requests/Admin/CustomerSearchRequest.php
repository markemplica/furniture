<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CustomerSearchRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['q' => trim((string) $this->input('q', ''))]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:200']];
    }
}
