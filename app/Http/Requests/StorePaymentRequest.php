<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Validates the shape of a payment. Balance and status rules need a row lock,
 * so they are enforced inside PaymentService, not here.
 */
class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Payment::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $trim = fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;

        $this->merge([
            'amount' => is_string($this->input('amount')) ? trim($this->input('amount')) : $this->input('amount'),
            'external_reference' => $trim($this->input('external_reference')),
            'notes' => $trim($this->input('notes')),
        ]);
    }

    public function rules(): array
    {
        return [
            'membership_transaction_id' => ['required', 'integer', Rule::exists('membership_transactions', 'id')],
            'amount' => [
                'required', 'string', 'regex:'.Money::INPUT_PATTERN,
                function (string $attribute, mixed $value, Closure $fail) {
                    if (is_string($value) && preg_match(Money::INPUT_PATTERN, $value) && Money::fromInput($value) <= 0) {
                        $fail('The payment amount must be greater than ₱0.00.');
                    }
                },
            ],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:2000-01-01'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.regex' => 'Enter an amount like 400 or 400.50 (no commas, no negative values, at most 2 decimals).',
            'method.enum' => 'Choose Cash, GCash or Bank transfer.',
            'paid_at.before_or_equal' => 'The payment date cannot be in the future.',
            'idempotency_key.*' => 'The payment form has expired. Reload the page and try again.',
        ];
    }

    public function transaction(): MembershipTransaction
    {
        return MembershipTransaction::findOrFail($this->validated('membership_transaction_id'));
    }

    /** Data for PaymentService::record(). The payment time is the chosen date at the current time. */
    public function paymentData(): array
    {
        $data = $this->safe()->only(['amount', 'method', 'external_reference', 'notes', 'idempotency_key']);
        $date = Carbon::createFromFormat('!Y-m-d', $this->validated('paid_at'));
        $data['paid_at'] = $date->isToday() ? now() : $date->setTime(12, 0);

        return $data;
    }
}
