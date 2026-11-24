<?php

namespace Tests\Feature\Payments;

use App\Enums\TransactionStatus;
use App\Exceptions\PaymentException;
use App\Models\Payment;
use App\Models\User;

class PaymentVoidTest extends PaymentTestCase
{
    public function test_voiding_keeps_the_record_and_recalculates_the_balance(): void
    {
        $transaction = $this->thousandPesoApplication();
        $keep = $this->pay($transaction, '400');
        $mistake = $this->pay($transaction, '250');

        $this->actingAs($this->admin)->patch(route('admin.payments.void', $mistake), ['void_reason' => 'Entered twice'])
            ->assertRedirect(route('admin.payments.show', $mistake))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'balance of ₱600.00'));

        $mistake->refresh();
        $this->assertNotNull($mistake->voided_at);
        $this->assertTrue($mistake->voider->is($this->admin));
        $this->assertSame('Entered twice', $mistake->void_reason);
        $this->assertSame(2, Payment::count()); // nothing deleted
        $this->assertNull($keep->fresh()->voided_at);

        $transaction->refresh();
        $this->assertSame('400.00', $transaction->amount_paid);
        $this->assertSame(TransactionStatus::Pending, $transaction->status);
        $this->assertCachedTotalMatchesPayments($transaction);
    }

    public function test_voiding_a_payment_on_a_paid_transaction_returns_it_to_pending(): void
    {
        $transaction = $this->thousandPesoApplication();
        $this->pay($transaction, '600');
        $last = $this->pay($transaction, '400');
        $this->assertSame(TransactionStatus::Paid, $transaction->fresh()->status);

        $this->payments->void($last, $this->admin, 'Bounced bank transfer');

        $transaction->refresh();
        $this->assertSame(TransactionStatus::Pending, $transaction->status);
        $this->assertSame(40000, $transaction->balanceCents());
        // Underpaid again, so approval is blocked until it is paid in full.
        $this->assertStringContainsString('Only fully paid', $this->service->approvalBlocker($transaction->load('member')));

        // A corrected payment brings it back to paid.
        $this->pay($transaction, '400');
        $this->assertSame(TransactionStatus::Paid, $transaction->fresh()->status);
    }

    public function test_void_reason_is_required(): void
    {
        $payment = $this->pay($this->thousandPesoApplication(), '100');

        $this->actingAs($this->admin)->patch(route('admin.payments.void', $payment), ['void_reason' => '  '])
            ->assertSessionHasErrors('void_reason');

        $this->assertNull($payment->fresh()->voided_at);
    }

    public function test_a_payment_cannot_be_voided_twice(): void
    {
        $payment = $this->pay($this->thousandPesoApplication(), '100');
        $stale = Payment::find($payment->id);

        $this->payments->void($payment, $this->admin, 'First void');
        $firstVoidedAt = $payment->fresh()->voided_at;

        $this->travel(1)->minutes();
        $this->actingAs($this->admin)->patch(route('admin.payments.void', $payment), ['void_reason' => 'Again'])
            ->assertSessionHas('error', 'This payment has already been voided.');

        try {
            $this->payments->void($stale, $this->admin, 'Stale second void');
            $this->fail('Second void must be rejected.');
        } catch (PaymentException $e) {
            $this->assertSame('This payment has already been voided.', $e->getMessage());
        }

        $this->assertEquals($firstVoidedAt, $payment->fresh()->voided_at);
        $this->assertSame('First void', $payment->fresh()->void_reason);
    }

    public function test_payments_cannot_be_voided_after_approval(): void
    {
        $transaction = $this->thousandPesoApplication();
        $payment = $this->pay($transaction, '1000');
        $this->service->approve($transaction->fresh(), $this->admin);

        $this->actingAs($this->admin)->patch(route('admin.payments.void', $payment), ['void_reason' => 'Too late'])
            ->assertSessionHas('error', 'The transaction has been approved, so its payments can no longer be voided.');

        $this->assertNull($payment->fresh()->voided_at);
        $this->assertSame('1000.00', $transaction->fresh()->amount_paid);
        $this->assertSame(TransactionStatus::Approved, $transaction->fresh()->status);
    }

    public function test_voided_payments_are_excluded_from_totals(): void
    {
        $transaction = $this->thousandPesoApplication();
        $this->pay($transaction, '300');
        $this->payments->void($this->pay($transaction, '200'), $this->admin, 'Wrong member');

        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $this->assertSame(30000, $this->actingAs($this->admin)->get(route('admin.dashboard'))->viewData('stats')['collected_total']);
        $this->assertSame(30000, $this->actingAs($this->admin)->get(route('admin.payments.index'))->viewData('totalCents'));
        $this->assertSame(30000, $this->actingAs($this->admin)->get(route('admin.members.history', $transaction->member))->viewData('totals')['paid']);
    }

    public function test_staff_cannot_void(): void
    {
        $payment = $this->pay($this->thousandPesoApplication(), '100');

        $this->actingAs(User::factory()->member()->create())
            ->patch(route('admin.payments.void', $payment), ['void_reason' => 'Nope'])
            ->assertForbidden();

        $this->assertNull($payment->fresh()->voided_at);
    }
}
