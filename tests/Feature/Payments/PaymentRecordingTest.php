<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Exceptions\PaymentException;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class PaymentRecordingTest extends PaymentTestCase
{
    public function test_partial_then_full_payment_moves_pending_to_paid_automatically(): void
    {
        $transaction = $this->thousandPesoApplication();

        // ₱400.00 → balance ₱600.00, still pending.
        $this->postPayment($transaction, ['amount' => '400', 'method' => 'gcash', 'external_reference' => 'GC-778899', 'notes' => 'First half'])
            ->assertRedirect(route('admin.payments.show', Payment::sole()))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Remaining balance: ₱600.00'));

        $first = Payment::sole();
        $transaction->refresh();
        $this->assertSame('400.00', $first->amount);
        $this->assertSame(PaymentMethod::GCash, $first->method);
        $this->assertSame('GC-778899', $first->external_reference);
        $this->assertSame('First half', $first->notes);
        $this->assertTrue($first->receiver->is($this->admin));
        $this->assertSame(today()->toDateString(), $first->paid_at->toDateString());
        $this->assertSame(sprintf('OR-2026-%06d', $first->id), $first->receipt_no);
        $this->assertSame('400.00', $transaction->amount_paid);
        $this->assertSame(60000, $transaction->balanceCents());
        $this->assertSame(TransactionStatus::Pending, $transaction->status);

        // ₱600.00 → balance ₱0.00, automatically paid.
        $this->postPayment($transaction, ['amount' => '600.00', 'method' => 'bank_transfer'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'now fully paid and ready for approval'));

        $transaction->refresh();
        $this->assertSame('1000.00', $transaction->amount_paid);
        $this->assertSame(0, $transaction->balanceCents());
        $this->assertSame(TransactionStatus::Paid, $transaction->status);
        $this->assertSame(2, Payment::count());
        $this->assertCachedTotalMatchesPayments($transaction);
    }

    public function test_many_small_payments_add_up_exactly_without_floating_point_errors(): void
    {
        $transaction = $this->thousandPesoApplication();

        foreach (['333.33', '333.33', '333.33', '0.01'] as $amount) {
            $this->pay($transaction, $amount);
        }

        $transaction->refresh();
        $this->assertSame('1000.00', $transaction->amount_paid);
        $this->assertSame(TransactionStatus::Paid, $transaction->status);
    }

    public function test_overpayment_is_rejected(): void
    {
        $transaction = $this->thousandPesoApplication();
        $this->pay($transaction, '400');

        $this->postPayment($transaction, ['amount' => '600.01'])
            ->assertSessionHasErrors(['amount' => 'The payment of ₱600.01 exceeds the remaining balance of ₱600.00.']);

        $this->assertSame(1, Payment::count());
        $this->assertSame('400.00', $transaction->fresh()->amount_paid);
    }

    public static function invalidAmounts(): array
    {
        return [
            'zero' => ['0'], 'zero decimals' => ['0.00'], 'negative' => ['-100'], 'comma' => ['1,000'],
            'three decimals' => ['10.005'], 'text' => ['abc'], 'empty' => [''], 'exponent' => ['1e3'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_are_rejected(string $amount): void
    {
        $transaction = $this->thousandPesoApplication();

        $this->postPayment($transaction, ['amount' => $amount])->assertSessionHasErrors('amount');

        $this->assertSame(0, Payment::count());
        $this->assertSame(TransactionStatus::Pending, $transaction->fresh()->status);
    }

    public function test_service_rejects_non_positive_amounts_even_if_validation_is_bypassed(): void
    {
        $transaction = $this->thousandPesoApplication();

        $this->expectException(PaymentException::class);
        $this->pay($transaction, '0.00');
    }

    public function test_invalid_method_date_and_transaction_are_rejected(): void
    {
        $transaction = $this->thousandPesoApplication();

        $this->postPayment($transaction, ['method' => 'card'])->assertSessionHasErrors('method');
        $this->postPayment($transaction, ['method' => 'bitcoin'])->assertSessionHasErrors('method');
        $this->postPayment($transaction, ['paid_at' => today()->addDay()->toDateString()])->assertSessionHasErrors('paid_at');
        $this->postPayment($transaction, ['paid_at' => '12/11/2026'])->assertSessionHasErrors('paid_at');
        $this->postPayment($transaction, ['membership_transaction_id' => 999999])->assertSessionHasErrors('membership_transaction_id');
        $this->postPayment($transaction, ['idempotency_key' => 'not-a-uuid'])->assertSessionHasErrors('idempotency_key');

        $this->assertSame(0, Payment::count());
    }

    public function test_payments_are_rejected_on_paid_cancelled_approved_and_expired_transactions(): void
    {
        $paid = $this->thousandPesoApplication();
        $this->pay($paid, '1000');

        $cancelled = $this->thousandPesoApplication();
        $this->service->cancel($cancelled, $this->admin, 'Withdrawn');

        $approved = $this->thousandPesoApplication();
        $this->pay($approved, '1000');
        $this->service->approve($approved->fresh(), $this->admin);

        $expired = $this->existingTransaction($this->pendingMember(), TransactionStatus::Expired, ['amount' => '1000.00', 'amount_paid' => '1000.00']);

        $expected = [
            [$paid, 'already fully paid'], [$cancelled, 'was cancelled'],
            [$approved, 'already been approved'], [$expired, 'has expired'],
        ];

        foreach ($expected as [$transaction, $message]) {
            $before = Payment::count();
            $this->postPayment($transaction, ['amount' => '1'])->assertSessionHasErrors('membership_transaction_id');
            $this->assertStringContainsString($message, session('errors')->first('membership_transaction_id'));
            $this->assertSame($before, Payment::count());
        }
    }

    public function test_duplicate_submission_with_the_same_key_creates_only_one_payment(): void
    {
        $transaction = $this->thousandPesoApplication();
        $key = (string) Str::uuid();

        $this->postPayment($transaction, ['amount' => '400', 'idempotency_key' => $key])->assertSessionHas('success');
        $payment = Payment::sole();

        $this->postPayment($transaction, ['amount' => '400', 'idempotency_key' => $key])
            ->assertRedirect(route('admin.payments.show', $payment))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'already recorded as '.$payment->receipt_no));

        $this->assertSame(1, Payment::count());
        $this->assertSame('400.00', $transaction->fresh()->amount_paid);
    }

    public function test_a_used_key_cannot_be_replayed_against_another_transaction(): void
    {
        $first = $this->thousandPesoApplication();
        $second = $this->thousandPesoApplication();
        $key = (string) Str::uuid();

        $this->postPayment($first, ['idempotency_key' => $key]);
        $this->postPayment($second, ['idempotency_key' => $key])->assertSessionHasErrors('idempotency_key');

        $this->assertSame(0, $second->payments()->count());
    }

    public function test_concurrent_payments_with_stale_balances_cannot_overpay(): void
    {
        $transaction = $this->thousandPesoApplication();
        $staleA = MembershipTransaction::find($transaction->id);
        $staleB = MembershipTransaction::find($transaction->id); // both believe ₱1,000.00 is outstanding

        $this->pay($staleA, '600');

        try {
            $this->pay($staleB, '600');
            $this->fail('The second payment must be rejected after re-reading the balance under the lock.');
        } catch (PaymentException $e) {
            $this->assertSame('The payment of ₱600.00 exceeds the remaining balance of ₱400.00.', $e->getMessage());
        }

        $this->pay($staleB, '400'); // the correct remainder is accepted
        $this->assertSame(TransactionStatus::Paid, $transaction->fresh()->status);
        $this->assertCachedTotalMatchesPayments($transaction);
    }

    public function test_cached_total_is_recalculated_from_payment_records(): void
    {
        $transaction = $this->thousandPesoApplication();
        $this->pay($transaction, '300');

        // Simulate a corrupted cache; the next payment recalculates from SUM(payments).
        $transaction->forceFill(['amount_paid' => '0.00'])->save();
        $this->pay($transaction, '200');

        $this->assertSame('500.00', $transaction->fresh()->amount_paid);
        $this->assertCachedTotalMatchesPayments($transaction);
    }

    public function test_receipt_numbers_are_unique(): void
    {
        foreach (range(1, 5) as $i) {
            $this->pay($this->thousandPesoApplication(), '100');
        }

        $this->assertCount(5, Payment::pluck('receipt_no')->unique());
    }

    public function test_server_controlled_fields_cannot_be_injected(): void
    {
        $transaction = $this->thousandPesoApplication();
        $other = User::factory()->admin()->create();

        $this->postPayment($transaction, [
            'amount' => '100', 'receipt_no' => 'OR-HACK', 'received_by' => $other->id,
            'amount_paid' => '1000.00', 'status' => 'paid', 'voided_at' => now()->toDateTimeString(),
        ]);

        $payment = Payment::sole();
        $this->assertNotSame('OR-HACK', $payment->receipt_no);
        $this->assertSame($this->admin->id, $payment->received_by);
        $this->assertNull($payment->voided_at);
        $this->assertSame('100.00', $transaction->fresh()->amount_paid);
        $this->assertSame(TransactionStatus::Pending, $transaction->fresh()->status);
    }
}
