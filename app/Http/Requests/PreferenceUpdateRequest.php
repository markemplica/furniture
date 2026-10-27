<?php

namespace App\Http\Requests;

use App\Models\FurnitureProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreferenceUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('budget_range')) {
            return;
        }

        $range = $this->input('budget_range');
        if (! is_string($range) || preg_match('/^\d+(?:\.\d{1,2})?-\d+(?:\.\d{1,2})?$/', $range) !== 1) {
            return;
        }

        [$minimum, $maximum] = explode('-', $range);
        $this->merge(['budget_min' => $minimum, 'budget_max' => $maximum]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rangeRules = ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?-\d+(?:\.\d{1,2})?$/'];
        $preferenceRules = [
            'furniture_type' => ['required', 'string', Rule::in(FurnitureProduct::recommendationTypes()->all())],
            'budget_min' => ['required', 'numeric', 'min:0'],
            'budget_max' => ['required', 'numeric', 'gt:budget_min'],
            'style' => ['nullable', 'string', 'max:80'],
            'wood_type' => ['nullable', Rule::in(FurnitureProduct::where('status', 'active')->distinct()->pluck('wood_type')->all())],
            'varnish_finish' => ['nullable', Rule::in(FurnitureProduct::where('status', 'active')->distinct()->pluck('varnish_finish')->all())],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];

        if (! $this->has('budget_range')) {
            return $preferenceRules;
        }

        $range = $this->input('budget_range');
        if (! is_string($range) || preg_match('/^\d+(?:\.\d{1,2})?-\d+(?:\.\d{1,2})?$/', $range) !== 1) {
            return ['budget_range' => $rangeRules];
        }

        return ['budget_range' => $rangeRules] + $preferenceRules;
    }
}
