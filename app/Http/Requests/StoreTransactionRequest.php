<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use App\Models\MembershipTransaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MembershipTransaction::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'member_id' => ['required', 'integer', Rule::exists('members', 'id')->whereNull('deleted_at')],
            'type' => ['required', Rule::enum(TransactionType::class)],
            // Applications use the member's current level; renewals may pick any active level.
            'membership_level_id' => [
                Rule::requiredIf(fn () => $this->input('type') === TransactionType::Renewal->value),
                'nullable', 'integer',
                Rule::exists('membership_levels', 'id')->where(fn (Builder $q) => $q->where('is_active', true)),
            ],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'member_id.exists' => 'Choose an existing member.',
            'membership_level_id.required' => 'Choose the level for this renewal.',
            'membership_level_id.exists' => 'Choose an active membership level.',
        ];
    }

    public function transactionType(): TransactionType
    {
        return TransactionType::from($this->validated('type'));
    }
}
