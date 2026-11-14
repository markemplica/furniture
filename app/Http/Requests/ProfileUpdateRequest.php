<?php

namespace App\Http\Requests;

use App\Support\NormalizesPhilippineMobileNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    use NormalizesPhilippineMobileNumber;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->user();
        $emailChanged = strtolower(trim((string) $this->input('email'))) !== strtolower(trim($user->email));

        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => $this->mobileNumberRules(),
            'current_password' => [Rule::excludeIf(! $emailChanged), 'required', 'string', 'current_password'],
        ];
    }
}
