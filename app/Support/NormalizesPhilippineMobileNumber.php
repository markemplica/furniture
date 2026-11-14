<?php

namespace App\Support;

trait NormalizesPhilippineMobileNumber
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('phone') || ! is_string($phone = $this->input('phone'))) {
            return;
        }

        // Reject oversized input rather than hiding its length by removing separators.
        if (strlen($phone) > 32) {
            return;
        }

        $phone = trim($phone);
        if ($phone === '') {
            $this->merge(['phone' => null]);

            return;
        }

        $compact = preg_replace('/[\s()\-]/u', '', $phone) ?? $phone;
        if ($compact === '') {
            return;
        }

        if (preg_match('/\A09[0-9]{9}\z/', $compact)) {
            $compact = '+63'.substr($compact, 1);
        } elseif (preg_match('/\A639[0-9]{9}\z/', $compact)) {
            $compact = '+'.$compact;
        } elseif (preg_match('/\A9[0-9]{9}\z/', $compact)) {
            $compact = '+63'.$compact;
        }

        $this->merge(['phone' => $compact]);
    }

    protected function mobileNumberRules(): array
    {
        return ['bail', 'required', 'string', 'max:13', 'regex:/\A\+639[0-9]{9}\z/'];
    }

    public function messages(): array
    {
        $message = 'Enter a Philippine mobile number such as 0917 123 4567 or +63 917 123 4567.';

        return ['phone.required' => 'Your mobile number is required.', 'phone.string' => $message, 'phone.max' => $message, 'phone.regex' => $message];
    }
}
