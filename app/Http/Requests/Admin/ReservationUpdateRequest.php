<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReservationUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['pending', 'confirmed', 'declined', 'cancelled'])],
            'notes' => ['nullable', 'string', 'max:1000'],
            'decline_reason' => ['nullable', 'required_if:status,declined', 'string', 'max:1000'],
            'item_ready' => ['nullable', 'boolean'],
            'outcome' => ['nullable', Rule::in(['completed', 'no_show'])],
        ];
    }
}
