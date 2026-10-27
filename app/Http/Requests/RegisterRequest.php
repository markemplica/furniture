<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:50'],
            'middle_name' => ['nullable', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:50'],
            'birthdate' => ['required', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['required', 'in:male,female,non_binary,prefer_not_to_say'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
