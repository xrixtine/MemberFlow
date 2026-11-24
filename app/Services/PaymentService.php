<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Exceptions\PaymentException;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use App\Support\NumberGenerator;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records and voids payments against membership transactions.
 *
 * Integrity rules:
 * - All money is handled as integer centavos (App\Support\Money); never floats.
 * - Every change runs in a DB transaction holding a row lock on the
 *   membership transaction (SELECT ... FOR UPDATE), so concurrent payments
 *   are serialised and can never overpay.
 * - amount_paid is recalculated from SUM(non-voided payments) under that lock,
 *   so the cached value always equals the payment records.
 * - Each payment form carries a unique idempotency key: a double submission
 *   returns the first payment instead of recording a second one.
 * - Payments are never edited or deleted. A mistaken payment is voided
 *   (row kept, reason recorded) and only before the transaction is approved.
 * - pending → paid happens here, automatically, when the balance reaches 0;
 *   paid → pending happens only when a void makes the transaction underpaid.
 */
class PaymentService
{
    /**
     * @param  array{amount: string, method: PaymentMethod|string, paid_at: CarbonInterface|string,
     *               external_reference?: ?string, notes?: ?string, idempotency_key: string}  $data
     */
    public function record(MembershipTransaction $transaction, array $data, User $receivedBy): PaymentResult
    {
        $key = (string) $data['idempotency_key'];

        try {
            return DB::transaction(function () use ($transaction, $data, $receivedBy, $key) {
                $txn = MembershipTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

                // Same form submitted twice: return the original payment.
                if ($existing = Payment::where('idempotency_key', $key)->first()) {
                    return $this->duplicateOf($existing, $txn);
                }

                $cents = $this->parseAmount((string) $data['amount']);
                $this->assertAcceptsPayment($txn);

                $paid = $this->validPaymentsTotal($txn);
                $balance = $txn->amountCents() - $paid;

                if ($cents > $balance) {
                    throw new PaymentException(
                        'The payment of '.Money::format($cents).' exceeds the remaining balance of '.Money::format($balance).'.'
                    );
                }

                $payment = new Payment([
                    'method' => $data['method'] instanceof PaymentMethod ? $data['method'] : PaymentMethod::from($data['method']),
                    'external_reference' => $data['external_reference'] ?? null,
                    'paid_at' => $data['paid_at'],
                    'notes' => $data['notes'] ?? null,
                ]);
                $payment->forceFill([
                    'membership_transaction_id' => $txn->id,
                    'amount' => Money::toDecimal($cents),
                    'received_by' => $receivedBy->id,
                    'idempotency_key' => $key,
                ])->save();

                $payment->forceFill([
                    'receipt_no' => NumberGenerator::receipt($payment->id, $payment->created_at),
                ])->save();

                $this->syncTransaction($txn, $paid + $cents);

                return new PaymentResult($payment->fresh());
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            // A simultaneous request with the same key won the race: return its payment.
            $existing = Payment::where('idempotency_key', $key)->first();
            if (! $existing) {
                throw $e;
            }

            return $this->duplicateOf($existing, $transaction);
        }
    }

    /** Why the transaction cannot take a payment now, or null when it can. */
    public function paymentBlocker(MembershipTransaction $transaction): ?string
    {
        return match ($transaction->status) {
            TransactionStatus::Pending => $transaction->balanceCents() > 0 ? null : 'This transaction has no remaining balance.',
            TransactionStatus::Paid => 'This transaction is already fully paid.',
            TransactionStatus::Approved => 'This transaction has already been approved; it cannot take new payments.',
            TransactionStatus::Expired => 'This transaction has expired; it cannot take new payments.',
            TransactionStatus::Cancelled => 'This transaction was cancelled; it cannot take new payments.',
        };
    }

    /** Why the payment cannot be voided, or null when it can. */
    public function voidBlocker(Payment $payment, ?MembershipTransaction $transaction = null): ?string
    {
        $transaction ??= $payment->transaction;

        if ($payment->isVoided()) {
            return 'This payment has already been voided.';
        }

        return match ($transaction->status) {
            TransactionStatus::Pending, TransactionStatus::Paid => null,
            TransactionStatus::Approved => 'The transaction has been approved, so its payments can no longer be voided.',
            TransactionStatus::Expired => 'The transaction has expired, so its payments can no longer be voided.',
            TransactionStatus::Cancelled => 'The transaction was cancelled, so its payments can no longer be voided.',
        };
    }

    /**
     * Void a payment: the row is kept, flagged with who/when/why, excluded from
     * totals, and the transaction's amount_paid and status are recalculated.
     */
    public function void(Payment $payment, User $by, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $by, $reason) {
            // Lock order (transaction, then payment) matches record() to avoid deadlocks.
            $txn = MembershipTransaction::whereKey($payment->membership_transaction_id)->lockForUpdate()->firstOrFail();
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($blocker = $this->voidBlocker($locked, $txn)) {
                throw new PaymentException($blocker, 'void_reason');
            }

            $locked->forceFill([
                'voided_at' => now(),
                'voided_by' => $by->id,
                'void_reason' => $reason,
            ])->save();

            $this->syncTransaction($txn, $this->validPaymentsTotal($txn));

            return $locked;
        }, attempts: 3);
    }

    /** SUM of non-voided payments in centavos, read from the payment rows themselves. */
    public function validPaymentsTotal(MembershipTransaction $transaction): int
    {
        return Money::fromDecimal(
            Payment::where('membership_transaction_id', $transaction->id)->whereNull('voided_at')->sum('amount')
        );
    }

    /**
     * Store the recalculated total and apply the automatic status changes:
     * pending → paid when the balance reaches zero, paid → pending when a void
     * leaves it underpaid. Any other status is left untouched.
     */
    private function syncTransaction(MembershipTransaction $txn, int $paidCents): void
    {
        $fullyPaid = $paidCents >= $txn->amountCents();
        $status = $txn->status;

        if ($fullyPaid && $status->canTransitionTo(TransactionStatus::Paid)) {
            $status = TransactionStatus::Paid;
        } elseif (! $fullyPaid && $status === TransactionStatus::Paid && $status->canTransitionTo(TransactionStatus::Pending)) {
            $status = TransactionStatus::Pending;
        }

        $txn->forceFill([
            'amount_paid' => Money::toDecimal($paidCents),
            'status' => $status,
        ])->save();
    }

    private function parseAmount(string $amount): int
    {
        try {
            $cents = Money::fromInput($amount);
        } catch (\InvalidArgumentException $e) {
            throw new PaymentException($e->getMessage());
        }

        if ($cents <= 0) {
            throw new PaymentException('The payment amount must be greater than ₱0.00.');
        }

        return $cents;
    }

    private function assertAcceptsPayment(MembershipTransaction $txn): void
    {
        if ($blocker = $this->paymentBlocker($txn)) {
            throw new PaymentException($blocker, 'membership_transaction_id');
        }
    }

    private function duplicateOf(Payment $existing, MembershipTransaction $transaction): PaymentResult
    {
        if ($existing->membership_transaction_id !== $transaction->id) {
            throw new PaymentException('This payment form was already used for a different transaction. Reload the page and try again.', 'idempotency_key');
        }

        return new PaymentResult($existing, duplicate: true);
    }
}
