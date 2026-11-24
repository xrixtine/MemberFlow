<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('admin');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? preg_replace('/\s+/', ' ', trim($this->input('name'))) : $this->input('name'),
            'fee' => is_string($this->input('fee')) ? trim($this->input('fee')) : $this->input('fee'),
            'is_active' => $this->boolean('is_active'),
            'sort_order' => $this->input('sort_order', 0) ?? 0,
        ]);
    }

    public function rules(): array
    {
        $level = $this->route('level');

        return [
            'name' => ['required', 'string', 'max:50', Rule::unique('membership_levels', 'name')->ignore($level?->id)],
            // Parsed as a string into integer cents: no floats, no commas, max 2 decimals.
            'fee' => ['required', 'string', 'regex:'.Money::INPUT_PATTERN],
            'validity_months' => ['required', 'integer', 'between:1,120'],
            'benefits' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'between:0,255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A level with this name already exists.',
            'fee.regex' => 'Enter the fee like 1500 or 1500.50 (no commas, no negative values, at most 2 decimals).',
            'validity_months.between' => 'Validity must be between 1 and 120 months.',
        ];
    }

    /** Validated data with the fee normalised to a DECIMAL(10,2) string. */
    public function levelData(): array
    {
        $data = $this->validated();
        $data['fee'] = Money::toDecimal(Money::fromInput($data['fee']));

        return $data;
    }
}
