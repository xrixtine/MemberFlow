<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesLocation;
use App\Support\FormTimer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Public membership application (/apply).
 *
 * Deliberately NOT validated here:
 *  - email uniqueness — checked in PublicApplicationService and answered with the
 *    same generic response, so the form cannot reveal whether an email is registered;
 *  - any status, number, amount or verification field — those are not accepted at all.
 * The honeypot is handled earlier by the "honeypot" middleware.
 */
class StorePublicApplicationRequest extends FormRequest
{
    use ValidatesLocation;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['first_name', 'middle_name', 'last_name', 'phone', 'address', 'latitude', 'longitude'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $clean[$field] = $value === '' ? null : $value;
            }
        }
        if (is_string($this->input('email'))) {
            $clean['email'] = strtolower(trim($this->input('email')));
        }

        $this->merge($clean);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'],
            'birthdate' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'address' => ['nullable', 'string', 'max:255'],
            ...$this->locationRules(),
            'membership_category_id' => ['required', 'integer', Rule::exists('membership_categories', 'id')->where('is_active', true)],
            'membership_level_id' => ['required', 'integer', Rule::exists('membership_levels', 'id')->where('is_active', true)],
            'privacy_consent' => ['accepted'],
            'form_token' => ['required', 'string'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('form_token')) {
                    return;
                }
                if ($problem = FormTimer::problem($this->input('form_token'))) {
                    $validator->errors()->add('form_token', $problem);
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number (digits, spaces, +, - and parentheses only).',
            'birthdate.before' => 'The birthdate must be in the past.',
            'membership_category_id.required' => 'Choose a membership category.',
            'membership_category_id.exists' => 'Choose an available membership category.',
            'membership_level_id.required' => 'Choose a membership level.',
            'membership_level_id.exists' => 'Choose an available membership level.',
            ...$this->locationMessages(),
            'privacy_consent.accepted' => 'Please confirm that you have read the privacy notice.',
            'form_token.required' => 'The form could not be verified. Please reload the page and submit again.',
        ];
    }

    public function attributes(): array
    {
        return [
            'membership_category_id' => 'category',
            'membership_level_id' => 'level',
        ];
    }
}
