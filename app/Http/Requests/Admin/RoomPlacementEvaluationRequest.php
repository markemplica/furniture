<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoomPlacementEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['notes' => ['nullable', 'string', 'max:2000']];
        foreach (['floor_detection_correct', 'zone_appropriate', 'clearance_respected', 'scale_plausible'] as $dimension) {
            $rules[$dimension] = ['required', Rule::in(['yes', 'no', 'not_applicable'])];
        }

        return $rules;
    }
}
