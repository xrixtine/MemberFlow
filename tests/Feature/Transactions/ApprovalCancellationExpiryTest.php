<?php

namespace Tests\Feature\Transactions;

use App\Enums\MemberSource;
use App\Enums\MemberStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\MembershipException;
use App\Models\MembershipTransaction;
use Carbon\CarbonImmutable;

class ApprovalCancellationExpiryTest extends TransactionTestCase
{
    // ---- Approval ---------------------------------------------------------------------

    public function test_unpaid_transactions_cannot_be_approved(): void
    {
        $transaction = $this->application();

        $this->actingAs($this->admin)->patch(route('admin.transactions.approve', $transaction))
            ->assertSessionHas('error', 'Only fully paid transactions can be approved. This one is pending.');

        $this->assertSame(TransactionStatus::Pending, $transaction->fresh()->status);
        $this->assertNull($transaction->fresh()->approved_at);
        $this->assertSame(MemberStatus::Pending, $transaction->member->fresh()->status);
    }

    public function test_partially_paid_or_inconsistent_transactions_cannot_be_approved(): void
    {
        $member = $this->pendingMember();

        // Pending with a partial payment.
        $partial = $this->existingTransaction($member, TransactionStatus::Pending, ['amount_paid' => '1500.00']);
        $this->assertStringContainsString('Only fully paid', $this->service->approvalBlocker($partial));

        // Defensive: status says paid but the balance is not zero.
        $inconsistent = $this->existingTransaction($this->pendingMember(), TransactionStatus::Paid, ['amount_paid' => '1999.99']);
        $this->assertSame('The transaction still has an outstanding balance of ₱0.01.', $this->service->approvalBlocker($inconsistent));
    }

    public function test_fully_paid_application_is_approved_and_activates_the_membership(): void
    {
        $transaction = $this->markFullyPaid($this->application());

        $this->actingAs($this->admin)->get(route('admin.transactions.show', $transaction))
            ->assertSee('Nov 12, 2026 – Nov 11, 2027')
            ->assertSee('Approve transaction');

        $this->actingAs($this->admin)->patch(route('admin.transactions.approve', $transaction))
            ->assertSessionHas('success');

        $transaction->refresh();
        $member = $transaction->member;

        $this->assertSame(TransactionStatus::Approved, $transaction->status);
        $this->assertTrue($transaction->approver->is($this->admin));
        $this->assertSame('2026-11-12 10:00:00', $transaction->approved_at->toDateTimeString());
        $this->assertSame('2026-11-12', $transaction->period_start->toDateString());
        $this->assertSame('2027-11-11', $transaction->period_end->toDateString());

        $this->assertSame(MemberStatus::Active, $member->status);
        $this->assertSame('2026-11-12', $member->membership_start->toDateString());
        $this->assertSame('2027-11-11', $member->membership_end->toDateString());
    }

    public function test_a_transaction_cannot_be_approved_twice(): void
    {
        $transaction = $this->markFullyPaid($this->application());
        $staleCopy = MembershipTransaction::find($transaction->id); // still "paid" in memory

        $this->service->approve($transaction, $this->admin);
        $firstApprovedAt = $transaction->fresh()->approved_at;

        $this->travel(5)->minutes();

        try {
            $this->service->approve($staleCopy, $this->admin);
            $this->fail('A second approval must be rejected.');
        } catch (MembershipException $e) {
            $this->assertSame('This transaction has already been approved.', $e->getMessage());
        }

        $this->actingAs($this->admin)->patch(route('admin.transactions.approve', $transaction))->assertSessionHas('error');
        $this->assertEquals($firstApprovedAt, $transaction->fresh()->approved_at);
    }

    public function test_online_applicants_must_verify_their_email_before_approval(): void
    {
        $online = $this->pendingMember(['source' => MemberSource::Online]);
        $transaction = $this->markFullyPaid($this->application($online));

        $this->actingAs($this->admin)->patch(route('admin.transactions.approve', $transaction))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'has not verified their email address'));
        $this->assertSame(TransactionStatus::Paid, $transaction->fresh()->status);

        $this->actingAs($this->admin)->get(route('admin.transactions.show', $transaction))
            ->assertSee('has not verified their email address')
            ->assertSee('disabled aria-disabled="true"', false);

        // Verification alone changes nothing about the transaction...
        $online->forceFill(['email_verified_at' => now()])->save();
        $this->assertSame(TransactionStatus::Paid, $transaction->fresh()->status);

        // ...but now an administrator may approve it.
        $this->actingAs($this->admin)->patch(route('admin.transactions.approve', $transaction))->assertSessionHas('success');
        $this->assertSame(TransactionStatus::Approved, $transaction->fresh()->status);
    }

    public function test_walk_in_members_are_exempt_from_email_verification(): void
    {
        $walkIn = $this->pendingMember(['source' => MemberSource::WalkIn, 'email_verified_at' => null]);

        $approved = $this->approvedApplicationFor($walkIn);

        $this->assertSame(TransactionStatus::Approved, $approved->status);
    }

    public function test_inactive_members_cannot_be_approved(): void
    {
        $member = $this->pendingMember();
        $transaction = $this->markFullyPaid($this->application($member));
        $member->forceFill(['status' => MemberStatus::Inactive])->save();

        $this->expectExceptionMessage('The member is inactive. Reactivate the member before approving.');
        $this->service->approve($transaction, $this->admin);
    }

    public function test_renewal_approved_before_expiry_continues_from_the_current_end_date(): void
    {
        $member = $this->activeMember('2026-12-31');
        $renewal = $this->markFullyPaid($this->service->openRenewal($member, $this->silver, $this->admin));

        $approved = $this->service->approve($renewal, $this->admin);
        $member->refresh();

        // No days lost or double-counted: starts the day after the old end date.
        $this->assertSame('2027-01-01', $approved->period_start->toDateString());
        $this->assertSame('2027-06-30', $approved->period_end->toDateString()); // Silver = 6 months
        $this->assertSame('2025-11-12', $member->membership_start->toDateString()); // continuous membership keeps its start
        $this->assertSame('2027-06-30', $member->membership_end->toDateString());
        $this->assertSame($this->silver->id, $member->membership_level_id);
        $this->assertSame(MemberStatus::Active, $member->status);
    }

    public function test_renewal_after_expiry_starts_on_the_approval_date(): void
    {
        $member = $this->expiredMember();
        $renewal = $this->markFullyPaid($this->service->openRenewal($member, $this->gold, $this->admin));

        $approved = $this->service->approve($renewal, $this->admin);
        $member->refresh();

        $this->assertSame('2026-11-12', $approved->period_start->toDateString());
        $this->assertSame('2027-11-11', $approved->period_end->toDateString());
        $this->assertSame('2026-11-12', $member->membership_start->toDateString());
        $this->assertSame(MemberStatus::Active, $member->status);
    }

    public function test_period_end_convention_handles_month_ends_and_leap_years(): void
    {
        $end = fn (string $start, int $months) => $this->service->periodEnd(CarbonImmutable::parse($start), $months)->toDateString();

        $this->assertSame('2027-11-11', $end('2026-11-12', 12));
        $this->assertSame('2026-02-27', $end('2026-01-31', 1));   // no overflow into March
        $this->assertSame('2026-12-31', $end('2026-01-01', 12));
        $this->assertSame('2029-02-27', $end('2028-02-29', 12));  // leap day start
        $this->assertSame('2027-05-11', $end('2026-11-12', 6));
    }

    // ---- Cancellation ---------------------------------------------------------------------

    public function test_unpaid_pending_transaction_can_be_cancelled_with_a_reason_and_is_kept(): void
    {
        $transaction = $this->application();

        $this->actingAs($this->admin)->patch(route('admin.transactions.cancel', $transaction), ['cancellation_reason' => ''])
            ->assertSessionHasErrors('cancellation_reason');
        $this->assertSame(TransactionStatus::Pending, $transaction->fresh()->status);

        $this->actingAs($this->admin)->patch(route('admin.transactions.cancel', $transaction), ['cancellation_reason' => 'Applicant withdrew'])
            ->assertSessionHas('success');

        $transaction->refresh();
        $this->assertSame(TransactionStatus::Cancelled, $transaction->status);
        $this->assertSame('Applicant withdrew', $transaction->cancellation_reason);
        $this->assertTrue($transaction->canceller->is($this->admin));
        $this->assertNotNull($transaction->cancelled_at);
        $this->assertSame(1, MembershipTransaction::count());
        // A walk-in member stays pending and can re-apply.
        $this->assertSame(MemberStatus::Pending, $transaction->member->fresh()->status);
    }

    public function test_cancellation_restrictions(): void
    {
        $partlyPaid = $this->existingTransaction($this->pendingMember(), TransactionStatus::Pending, ['amount_paid' => '500.00']);
        $paid = $this->markFullyPaid($this->application());
        $approved = $this->approvedApplicationFor($this->pendingMember());
        $cancelled = $this->existingTransaction($this->pendingMember(), TransactionStatus::Cancelled);

        $this->assertSame('Payments have been recorded on this transaction, so it cannot be cancelled.', $this->service->cancellationBlocker($partlyPaid));
        foreach ([$paid, $approved, $cancelled] as $transaction) {
            $this->actingAs($this->admin)->patch(route('admin.transactions.cancel', $transaction), ['cancellation_reason' => 'Try'])
                ->assertSessionHas('error');
        }

        $this->assertSame(TransactionStatus::Paid, $paid->fresh()->status);
        $this->assertSame(TransactionStatus::Approved, $approved->fresh()->status);
        $this->assertSame(TransactionStatus::Pending, $partlyPaid->fresh()->status);
    }

    public function test_cancelling_an_online_application_deactivates_the_online_applicant(): void
    {
        $online = $this->pendingMember(['source' => MemberSource::Online]);
        $transaction = $this->application($online);

        $this->service->cancel($transaction, $this->admin, 'Duplicate online submission');

        $this->assertSame(MemberStatus::Inactive, $online->fresh()->status);
    }

    public function test_cancelled_and_expired_transactions_are_final(): void
    {
        foreach ([TransactionStatus::Cancelled, TransactionStatus::Expired] as $status) {
            $transaction = $this->existingTransaction($this->pendingMember(), $status, ['amount_paid' => '0.00']);
            $this->assertNotNull($this->service->approvalBlocker($transaction));
            $this->assertNotNull($this->service->cancellationBlocker($transaction));
        }
    }

    // ---- Expiry ------------------------------------------------------------------------

    public function test_expiry_uses_the_inclusive_end_date(): void
    {
        $member = $this->pendingMember();
        $approved = $this->approvedApplicationFor($member); // valid through 2027-11-11

        $this->travelTo(CarbonImmutable::parse('2027-11-11 23:00'));
        $this->artisan('memberflow:expire')->expectsOutputToContain('Expired 0 transaction(s) and 0 member(s)')->assertSuccessful();
        $this->assertSame(MemberStatus::Active, $member->fresh()->status);

        $this->travelTo(CarbonImmutable::parse('2027-11-12 00:10'));
        $this->artisan('memberflow:expire')->expectsOutputToContain('Expired 1 transaction(s) and 1 member(s)')->assertSuccessful();
        $this->assertSame(MemberStatus::Expired, $member->fresh()->status);
        $this->assertSame(TransactionStatus::Expired, $approved->fresh()->status);

        // Running it again changes nothing.
        $this->assertSame(['transactions' => 0, 'members' => 0], $this->service->expireDue());
    }

    public function test_member_with_an_approved_renewal_is_not_expired_when_the_old_period_ends(): void
    {
        $member = $this->pendingMember();
        $first = $this->approvedApplicationFor($member); // 2026-11-12 → 2027-11-11

        $this->travelTo(CarbonImmutable::parse('2027-10-01'));
        $renewal = $this->markFullyPaid($this->service->openRenewal($member->fresh(), $this->gold, $this->admin));
        $second = $this->service->approve($renewal, $this->admin); // 2027-11-12 → 2028-11-11

        $this->travelTo(CarbonImmutable::parse('2027-11-20'));
        $this->service->expireDue();

        $this->assertSame(TransactionStatus::Expired, $first->fresh()->status);   // the old period did end
        $this->assertSame(TransactionStatus::Approved, $second->fresh()->status); // the renewal is running
        $this->assertSame(MemberStatus::Active, $member->fresh()->status);
        $this->assertSame('2028-11-11', $member->fresh()->membership_end->toDateString());
    }

    public function test_expiry_does_not_touch_inactive_pending_or_cancelled_records(): void
    {
        $inactive = $this->pendingMember();
        $inactive->forceFill(['status' => MemberStatus::Inactive, 'membership_end' => '2020-01-01'])->save();
        $pending = $this->application();
        $cancelled = $this->existingTransaction($this->pendingMember(), TransactionStatus::Cancelled, ['period_end' => '2020-01-01']);

        $this->service->expireDue();

        $this->assertSame(MemberStatus::Inactive, $inactive->fresh()->status);
        $this->assertSame(TransactionStatus::Pending, $pending->fresh()->status);
        $this->assertSame(TransactionStatus::Cancelled, $cancelled->fresh()->status);
    }

    public function test_admin_can_run_the_expiry_check_from_the_transactions_page(): void
    {
        $member = $this->activeMember('2026-11-01');

        $this->actingAs($this->admin)->post(route('admin.transactions.expire'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 member marked expired'));

        $this->assertSame(MemberStatus::Expired, $member->fresh()->status);
    }

    public function test_expiry_command_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('memberflow:expire')->assertSuccessful();
    }

    public function test_type_is_recorded_on_renewal_transactions(): void
    {
        $renewal = $this->service->openRenewal($this->expiredMember(), $this->gold, $this->admin);
        $this->assertSame(TransactionType::Renewal, $renewal->type);
    }
}
