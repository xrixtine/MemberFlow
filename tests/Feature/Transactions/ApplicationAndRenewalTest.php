<?php

namespace Tests\Feature\Transactions;

use App\Enums\MemberStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\MembershipException;
use App\Models\MembershipLevel;
use App\Models\MembershipTransaction;

class ApplicationAndRenewalTest extends TransactionTestCase
{
    // ---- Applications -----------------------------------------------------------

    public function test_admin_creates_an_application_for_a_registered_member(): void
    {
        $member = $this->pendingMember();

        $this->actingAs($this->admin)->get(route('admin.transactions.create', ['member' => $member->id]))
            ->assertOk()->assertSee('Create new application');

        $response = $this->actingAs($this->admin)->post(route('admin.transactions.store'), [
            'member_id' => $member->id, 'type' => 'application', 'remarks' => 'Walk-in, paying next week',
        ]);

        $transaction = MembershipTransaction::sole();
        $response->assertRedirect(route('admin.transactions.show', $transaction))->assertSessionHas('success');

        $this->assertSame(TransactionType::Application, $transaction->type);
        $this->assertSame(TransactionStatus::Pending, $transaction->status);
        $this->assertSame('2000.00', $transaction->amount);
        $this->assertSame('0.00', $transaction->amount_paid);
        $this->assertSame(12, $transaction->validity_months);
        $this->assertSame($this->gold->id, $transaction->membership_level_id);
        $this->assertSame('Walk-in, paying next week', $transaction->remarks);
        $this->assertSame(sprintf('TXN-20261112-%06d', $transaction->id), $transaction->reference_no);
        $this->assertTrue($transaction->creator->is($this->admin));
        // Creating a transaction never activates the membership.
        $this->assertSame(MemberStatus::Pending, $member->fresh()->status);
    }

    public function test_duplicate_application_is_prevented_while_one_is_open(): void
    {
        $member = $this->pendingMember();
        $this->application($member);

        $this->actingAs($this->admin)->post(route('admin.transactions.store'), ['member_id' => $member->id, 'type' => 'application'])
            ->assertSessionHas('error', 'The member already has an open (pending or paid) transaction.');

        $this->assertSame(1, MembershipTransaction::count());
    }

    public function test_concurrent_requests_with_stale_data_cannot_open_two_transactions(): void
    {
        $member = $this->pendingMember();
        $staleCopy = $member->replicate()->setRawAttributes($member->getAttributes(), true);
        $staleCopy->exists = true;

        $this->service->openApplication($member, $this->admin);

        // The second request still holds pre-insert data; the service re-reads under a row lock.
        $this->expectException(MembershipException::class);
        $this->service->openApplication($staleCopy, $this->admin);
    }

    public function test_application_is_refused_for_members_who_already_had_a_membership_or_are_inactive(): void
    {
        $approved = $this->pendingMember();
        $this->approvedApplicationFor($approved);
        $this->assertStringContainsString('Use a renewal instead', $this->service->applicationBlocker($approved->fresh()));

        $inactive = $this->pendingMember(['status' => MemberStatus::Inactive]);
        $this->assertStringContainsString('Reactivate the member first', $this->service->applicationBlocker($inactive));

        $this->actingAs($this->admin)->post(route('admin.transactions.store'), ['member_id' => $inactive->id, 'type' => 'application'])
            ->assertSessionHas('error');
        $this->assertSame(0, $inactive->transactions()->count());
    }

    public function test_member_can_reapply_after_a_cancelled_application(): void
    {
        $member = $this->pendingMember();
        $first = $this->application($member);
        $this->service->cancel($first, $this->admin, 'Applicant changed level');

        $second = $this->application($member->fresh());

        $this->assertSame(TransactionStatus::Pending, $second->status);
        $this->assertSame(TransactionStatus::Cancelled, $first->fresh()->status);
    }

    public function test_deleted_member_cannot_get_a_transaction(): void
    {
        $member = $this->pendingMember();
        $member->delete();

        $this->actingAs($this->admin)->post(route('admin.transactions.store'), ['member_id' => $member->id, 'type' => 'application'])
            ->assertSessionHasErrors('member_id');
    }

    public function test_reference_numbers_are_unique_and_sequential_by_id(): void
    {
        $references = collect(range(1, 6))->map(fn () => $this->application()->reference_no);

        $this->assertCount(6, $references->unique());
        $this->assertSame($references->all(), MembershipTransaction::orderBy('id')->pluck('reference_no')->all());
    }

    // ---- Snapshots ---------------------------------------------------------------------

    public function test_fee_and_validity_are_snapshotted(): void
    {
        $member = $this->pendingMember();
        $transaction = $this->application($member);

        $this->gold->update(['fee' => '9999.00', 'validity_months' => 1]);

        $transaction->refresh();
        $this->assertSame('2000.00', $transaction->amount);
        $this->assertSame(12, $transaction->validity_months);

        // Approval uses the snapshot validity, not the level's current one.
        $approved = $this->service->approve($this->markFullyPaid($transaction), $this->admin);
        $this->assertSame('2027-11-11', $approved->period_end->toDateString());
    }

    public function test_zero_fee_level_creates_a_transaction_that_is_already_paid(): void
    {
        $free = MembershipLevel::factory()->create(['fee' => '0.00', 'validity_months' => 12]);
        $member = $this->pendingMember(['membership_level_id' => $free->id]);

        $transaction = $this->application($member);

        $this->assertSame(TransactionStatus::Paid, $transaction->status);
        $this->assertSame(0, $transaction->balanceCents());
        $this->assertNull($this->service->approvalBlocker($transaction->fresh()));
    }

    // ---- Renewal eligibility --------------------------------------------------------------

    public function test_renewal_window_opens_60_days_before_expiry(): void
    {
        // Today is 2026-11-12 → the window covers memberships ending on or before 2027-01-11.
        $inside = $this->activeMember('2027-01-11');
        $outside = $this->activeMember('2027-01-12');

        $this->assertNull($this->service->renewalBlocker($inside));
        $this->assertStringContainsString('Renewal opens 60 days before expiry (from Nov 13, 2026)', $this->service->renewalBlocker($outside));

        $this->actingAs($this->admin)->post(route('admin.transactions.store'), [
            'member_id' => $outside->id, 'type' => 'renewal', 'membership_level_id' => $this->gold->id,
        ])->assertSessionHas('error');
        $this->assertSame(0, MembershipTransaction::count());
    }

    public function test_expired_members_can_renew(): void
    {
        $member = $this->expiredMember();

        $this->actingAs($this->admin)->post(route('admin.transactions.store'), [
            'member_id' => $member->id, 'type' => 'renewal', 'membership_level_id' => $this->gold->id,
        ])->assertSessionHas('success');

        $renewal = MembershipTransaction::sole();
        $this->assertSame(TransactionType::Renewal, $renewal->type);
        $this->assertSame(TransactionStatus::Pending, $renewal->status);
    }

    public function test_renewal_is_refused_for_pending_inactive_or_members_with_an_open_transaction(): void
    {
        $this->assertStringContainsString('no approved membership yet', $this->service->renewalBlocker($this->pendingMember()));

        $inactive = $this->activeMember('2026-12-01');
        $inactive->forceFill(['status' => MemberStatus::Inactive])->save();
        $this->assertStringContainsString('inactive', $this->service->renewalBlocker($inactive));

        $busy = $this->activeMember('2026-12-01');
        $this->service->openRenewal($busy, $this->gold, $this->admin);
        $this->assertStringContainsString('open (pending or paid) transaction', $this->service->renewalBlocker($busy->fresh()));

        $this->expectException(MembershipException::class);
        $this->service->openRenewal($busy->fresh(), $this->silver, $this->admin);
    }

    public function test_renewal_at_a_different_level_snapshots_that_level(): void
    {
        $member = $this->activeMember('2026-12-31');

        $this->actingAs($this->admin)->post(route('admin.transactions.store'), [
            'member_id' => $member->id, 'type' => 'renewal', 'membership_level_id' => $this->silver->id,
        ]);

        $renewal = MembershipTransaction::sole();
        $this->assertSame($this->silver->id, $renewal->membership_level_id);
        $this->assertSame('1000.00', $renewal->amount);
        $this->assertSame(6, $renewal->validity_months);
        // The member's level only changes when the renewal is approved.
        $this->assertSame($this->gold->id, $member->fresh()->membership_level_id);
    }

    public function test_renewal_requires_an_active_level(): void
    {
        $member = $this->activeMember('2026-12-31');
        $retired = MembershipLevel::factory()->inactive()->create();

        $this->actingAs($this->admin)->post(route('admin.transactions.store'), [
            'member_id' => $member->id, 'type' => 'renewal', 'membership_level_id' => $retired->id,
        ])->assertSessionHasErrors('membership_level_id');

        $this->actingAs($this->admin)->post(route('admin.transactions.store'), [
            'member_id' => $member->id, 'type' => 'renewal',
        ])->assertSessionHasErrors('membership_level_id');

        $this->actingAs($this->admin)->post(route('admin.transactions.store'), [
            'member_id' => $member->id, 'type' => 'upgrade',
        ])->assertSessionHasErrors('type');
    }

    public function test_create_page_explains_ineligibility_and_offers_only_valid_actions(): void
    {
        $member = $this->activeMember('2027-06-30');

        $this->actingAs($this->admin)->get(route('admin.transactions.create', ['member' => $member->id, 'type' => 'renewal']))
            ->assertOk()
            ->assertSee('Renewal opens 60 days before expiry')
            ->assertDontSee('Create renewal');

        $this->actingAs($this->admin)->get(route('admin.members.show', $member))
            ->assertDontSee('Renew membership')
            ->assertDontSee('New application');
    }

    public function test_member_picker_search(): void
    {
        $member = $this->pendingMember(['first_name' => 'Lorna', 'last_name' => 'Tolentino']);

        $this->actingAs($this->admin)->get(route('admin.transactions.create'))->assertOk()->assertSee('Search for a member');
        $this->actingAs($this->admin)->get(route('admin.transactions.create', ['q' => 'Tolentino']))
            ->assertSee('Tolentino, Lorna')
            ->assertSee(route('admin.transactions.create', ['member' => $member->id]), false);
    }
}
