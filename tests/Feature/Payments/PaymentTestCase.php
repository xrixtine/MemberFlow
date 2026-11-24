<?php

namespace Tests\Feature\Payments;

use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Transactions\TransactionTestCase;

/**
 * Payment fixtures: a ₱1,000.00 Silver application by default.
 */
abstract class PaymentTestCase extends TransactionTestCase
{
    protected PaymentService $payments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payments = app(PaymentService::class);
        $this->silver->update(['fee' => '1000.00']);
    }

    protected function thousandPesoApplication(): MembershipTransaction
    {
        return $this->application($this->pendingMember(['membership_level_id' => $this->silver->id]));
    }

    protected function postPayment(MembershipTransaction $transaction, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.payments.store'), array_merge([
            'membership_transaction_id' => $transaction->id,
            'amount' => '400.00',
            'method' => 'cash',
            'paid_at' => today()->toDateString(),
            'external_reference' => null,
            'notes' => null,
            'idempotency_key' => (string) Str::uuid(),
        ], $overrides));
    }

    /** Record through the real service (used to arrange state). */
    protected function pay(MembershipTransaction $transaction, string $amount, string $method = 'cash'): Payment
    {
        return $this->payments->record($transaction, [
            'amount' => $amount, 'method' => $method, 'paid_at' => now(), 'idempotency_key' => (string) Str::uuid(),
        ], $this->admin)->payment;
    }

    protected function assertCachedTotalMatchesPayments(MembershipTransaction $transaction): void
    {
        $transaction->refresh();
        $this->assertSame(
            $this->payments->validPaymentsTotal($transaction),
            $transaction->paidCents(),
            'amount_paid must equal SUM(non-voided payments)'
        );
    }
}
