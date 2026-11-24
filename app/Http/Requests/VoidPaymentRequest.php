<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('void', $this->route('payment')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('void_reason'))) {
            $this->merge(['void_reason' => trim($this->input('void_reason'))]);
        }
    }

    public function rules(): array
    {
        return ['void_reason' => ['required', 'string', 'min:3', 'max:255']];
    }

    public function messages(): array
    {
        return ['void_reason.required' => 'A reason is required to void a payment.'];
    }
}
