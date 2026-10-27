<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyRoomPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preview' => ['required', 'string'],
            'placement' => ['required', 'array'],
            'placement.mode' => ['required', 'in:floor,wall,door'],
            'placement.x' => ['required', 'numeric', 'between:0,1'],
            'placement.y' => ['required', 'numeric', 'between:0,1'],
            'placement.width' => ['required', 'numeric', 'between:0,1'],
            'placement.height' => ['required', 'numeric', 'between:0,1'],
            'placement.scale' => ['required', 'numeric', 'between:0.6,1.3'],
        ];
    }
}
