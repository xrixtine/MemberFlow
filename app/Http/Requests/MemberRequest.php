<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesLocation;
use App\Models\Member;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared validation for registering and editing members.
 */
abstract class MemberRequest extends FormRequest
{
    use ValidatesLocation;

    /** The member being edited, or null when registering. */
    abstract protected function member(): ?Member;

    public function authorize(): bool
    {
        return Gate::allows('admin');
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];
        foreach (['first_name', 'middle_name', 'last_name', 'phone', 'address', 'latitude', 'longitude'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $trimmed[$field] = $value === '' ? null : $value;
            }
        }

        if (is_string($this->input('email'))) {
            $trimmed['email'] = strtolower(trim($this->input('email')));
        }

        $this->merge($trimmed);
    }

    public function rules(): array
    {
        $member = $this->member();

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            // Unique across ALL members, including soft-deleted ones (the DB index is global too).
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('members', 'email')->ignore($member?->id)],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'],
            'birthdate' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'address' => ['nullable', 'string', 'max:255'],
            ...$this->locationRules(),
            'membership_category_id' => ['required', 'integer', $this->activeOrCurrent('membership_categories', $member?->membership_category_id)],
            'membership_level_id' => ['required', 'integer', $this->activeOrCurrent('membership_levels', $member?->membership_level_id)],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'A member with this email address is already registered.',
            'phone.regex' => 'Enter a valid phone number (digits, spaces, +, - and parentheses only).',
            'birthdate.before' => 'The birthdate must be in the past.',
            'membership_category_id.required' => 'Choose a membership category.',
            'membership_category_id.exists' => 'Choose an active membership category.',
            'membership_level_id.required' => 'Choose a membership level.',
            'membership_level_id.exists' => 'Choose an active membership level.',
            ...$this->locationMessages(),
        ];
    }

    public function attributes(): array
    {
        return [
            'membership_category_id' => 'category',
            'membership_level_id' => 'level',
        ];
    }

    /**
     * The id must exist and be active, except that an existing member may keep
     * a category/level that has since been deactivated.
     */
    private function activeOrCurrent(string $table, ?int $currentId): Exists
    {
        return Rule::exists($table, 'id')->where(function (Builder $query) use ($currentId) {
            $query->where('is_active', true);
            if ($currentId) {
                $query->orWhere('id', $currentId);
            }
        });
    }
}
