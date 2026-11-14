<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['redirect' => ['nullable', 'string', 'max:2048']];

        if ($this->isMethod('POST')) {
            $rules += [
                'email' => ['bail', 'required', 'string', 'email', 'max:255'],
                'password' => ['bail', 'required', 'string', 'max:1024'],
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'redirect.string' => 'The sign-in return link is invalid. Please sign in again.',
            'redirect.max' => 'The sign-in return link is too long. Please sign in again.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        // Render a usable form without flashing malformed arrays or passwords into old input.
        $email = $this->input('email');
        $redirect = $this->input('redirect');

        throw new ValidationException($validator, response()->view('auth.login', [
            'email' => is_string($email) && mb_strlen($email) <= 255 ? $email : '',
            'redirect' => ! $validator->errors()->has('redirect') && is_string($redirect) ? $redirect : '',
            'errors' => (new ViewErrorBag)->put('default', $validator->errors()),
        ], 422));
    }
}
