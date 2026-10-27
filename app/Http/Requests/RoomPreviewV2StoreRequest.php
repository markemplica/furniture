<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RoomPreviewV2StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['furniture_id' => ['required', 'integer', 'min:1'], 'retry' => ['sometimes', 'boolean']];
    }
}
