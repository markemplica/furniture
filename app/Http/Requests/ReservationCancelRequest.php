<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReservationCancelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['cancellation_reason' => ['nullable', 'string', 'max:1000']];
    }
}
