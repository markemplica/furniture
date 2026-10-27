<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReservationStoreRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing(['branch' => config('furnicraft.branch')]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'furniture_product_id' => ['required', 'exists:furniture_products,id'],
            'recommendation_id' => ['nullable', 'exists:recommendations,id'],
            'branch' => ['required', Rule::in(config('furnicraft.branches'))],
            'preferred_visit_at' => ['nullable', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'customization_requested' => ['nullable', 'boolean'],
            'custom_width_cm' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'custom_depth_cm' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'custom_height_cm' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'custom_wood_type' => ['nullable', 'string', 'max:80'],
            'custom_varnish_finish' => ['nullable', 'string', 'max:80'],
            'custom_design_notes' => ['nullable', 'string', 'max:1500', 'required_if:customization_requested,1'],
        ];
    }
}
