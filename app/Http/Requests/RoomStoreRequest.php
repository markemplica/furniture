<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RoomStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'room_width_m' => ['nullable', 'numeric', 'min:1', 'max:50', 'required_with:room_depth_m'],
            'room_depth_m' => ['nullable', 'numeric', 'min:1', 'max:50', 'required_with:room_width_m'],
            'return_to' => ['nullable', 'in:recommendations'],
            'gemini_consent' => ['nullable', 'boolean'],
        ];
    }
}
