<?php

namespace Tests\Feature\Payments;

use App\Enums\MemberSource;
use App\Enums\MemberStatus;
use App\Enums\TransactionStatus;
use App\Models\Member;
use App\Models\MembershipTransaction;
use App\Models\Payment;

/**
 * The full workflows through real HTTP routes: no fixtures mark anything paid.
 */
class PaymentWorkflowTest extends PaymentTestCase
{
    public function test_registration_to_partial_and_full_payment_to_approval(): void
    {
        // 1–2. Register a member → pending application.
        $this->actingAs($this->admin)->post(route('admin.members.store'), [
            'first_name' => 'Joy', 'last_name' => 'Santos', 'email' => 'joy.santos@example.test',
            'membership_category_id' => $this->category->id, 'membership_level_id' => $this->silver->id, 'create_application' => '1',
        ])->assertSessionHasNoErrors();
        $member = Member::sole();
        $transaction = MembershipTransaction::sole();
        $this->assertSame(TransactionStatus::Pending, $transaction->status);

        // 3–4. Partial payment → still pending, approval refused.
        $this->postPayment($transaction, ['amount' => '400']);
        $this->assertSame(TransactionStatus::Pending, $transaction->fresh()->status);
        $this->actingAs($this->admin)->patch(route('admin.transactions.approve', $transaction))->assertSessionHas('error');
        $this->assertSame(MemberStatus::Pending, $member->fresh()->status);

        // 5–6. Remaining balance → paid.
        $this->postPayment($transaction, ['amount' => '600', 'method' => 'gcash']);
        $this->assertSame(TransactionStatus::Paid, $transaction->fresh()->status);

        // 7–9. Approve → active with correct validity (Silver = 6 months).
        $this->actingAs($this->admin)->patch(route('admin.transactions.approve', $transaction))->assertSessionHas('success');
        $transaction->refresh();
        $member->refresh();
        $this->assertSame(TransactionStatus::Approved, $transaction->status);
        $this->assertSame(MemberStatus::Active, $member->status);
        $this->assertSame('2026-11-12', $member->membership_start->toDateString());
        $this->assertSame('2027-05-11', $member->membership_end->toDateString());

        // 10. Histories are accurate.
        $history = $this->actingAs($this->admin)->get(route('admin.members.history', $member));
        $history->assertSee($transaction->reference_no);
        foreach (Payment::all() as $payment) {
            $history->assertSee($payment->receipt_no);
        }
        $this->assertSame(['billed' => 100000, 'paid' => 100000, 'balance' => 0, 'approved' => 1], $history->viewData('totals'));
        $this->assertCachedTotalMatchesPayments($transaction);
    }

    public function test_renewal_payment_and_approval_extend_the_membership(): void
    {
        $member = $this->activeMember('2026-12-31');

        $this->actingAs($this->admin)->post(route('admin.transactions.store'), [
            'member_id' => $member->id, 'type' => 'renewal', 'membership_level_id' => $this->gold->id,
        ]);
        $renewal = MembershipTransaction::sole();
        $this->assertSame('2000.00', $renewal->amount);

        $this->postPayment($renewal, ['amount' => '1500']);
        $this->postPayment($renewal, ['amount' => '500']);
        $this->assertSame(TransactionStatus::Paid, $renewal->fresh()->status);

        $this->actingAs($this->admin)->patch(route('admin.transactions.approve', $renewal))->assertSessionHas('success');

        $member->refresh();
        $this->assertSame('2027-01-01', $renewal->fresh()->period_start->toDateString());
        $this->assertSame('2027-12-31', $member->membership_end->toDateString());
        $this->assertSame(MemberStatus::Active, $member->status);
    }

    public function test_online_applicant_paid_in_full_still_needs_email_verification(): void
    {
        $online = $this->pendingMember(['source' => MemberSource::Online, 'membership_level_id' => $this->silver->id]);
        $transaction = $this->application($online);

        $this->postPayment($transaction, ['amount' => '1000']);
        $this->assertSame(TransactionStatus::Paid, $transaction->fresh()->status);

        $this->actingAs($this->admin)->patch(route('admin.transactions.approve', $transaction))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'not verified their email'));

        $online->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($this->admin)->patch(route('admin.transactions.approve', $transaction))->assertSessionHas('success');
        $this->assertSame(MemberStatus::Active, $online->fresh()->status);
    }

    public function test_void_after_full_payment_blocks_approval_until_paid_again(): void
    {
        $transaction = $this->thousandPesoApplication();
        $this->postPayment($transaction, ['amount' => '1000']);
        $payment = Payment::sole();

        $this->actingAs($this->admin)->patch(route('admin.payments.void', $payment), ['void_reason' => 'Counterfeit bill']);
        $this->assertSame(TransactionStatus::Pending, $transaction->fresh()->status);
        $this->actingAs($this->admin)->patch(route('admin.transactions.approve', $transaction))->assertSessionHas('error');

        $this->postPayment($transaction, ['amount' => '1000']);
        $this->actingAs($this->admin)->patch(route('admin.transactions.approve', $transaction))->assertSessionHas('success');
        $this->assertSame(TransactionStatus::Approved, $transaction->fresh()->status);
        $this->assertSame(2, Payment::count());
        $this->assertCachedTotalMatchesPayments($transaction);
    }

    public function test_cancelling_is_refused_once_a_payment_exists_but_allowed_after_it_is_voided(): void
    {
        $transaction = $this->thousandPesoApplication();
        $payment = $this->pay($transaction, '200');

        $this->actingAs($this->admin)->patch(route('admin.transactions.cancel', $transaction), ['cancellation_reason' => 'Withdrawn'])
            ->assertSessionHas('error');

        $this->payments->void($payment, $this->admin, 'Refunded at the counter');
        $this->actingAs($this->admin)->patch(route('admin.transactions.cancel', $transaction->fresh()), ['cancellation_reason' => 'Withdrawn'])
            ->assertSessionHas('success');

        $this->assertSame(TransactionStatus::Cancelled, $transaction->fresh()->status);
        $this->assertSame(1, Payment::count()); // voided payment kept
    }
}
