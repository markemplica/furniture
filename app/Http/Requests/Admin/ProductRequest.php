<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string', 'max:80'],
            'description' => ['required', 'string', 'max:3000'],
            'wood_type' => ['required', 'string', 'max:100'],
            'varnish_finish' => ['required', 'string', 'max:100'],
            'style' => ['required', 'string', 'max:80'],
            'price' => ['required', 'numeric', 'min:0'],
            'width_cm' => ['nullable', 'numeric', 'min:1'],
            'depth_cm' => ['nullable', 'numeric', 'min:1'],
            'height_cm' => ['nullable', 'numeric', 'min:1'],
            'tags' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }
}
