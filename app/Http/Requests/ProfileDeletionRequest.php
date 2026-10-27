<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProfileDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
            'confirm_account_deletion' => ['required', 'in:DELETE'],
        ];
    }
}
