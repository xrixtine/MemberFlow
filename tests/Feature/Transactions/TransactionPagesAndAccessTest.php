<?php

namespace Tests\Feature\Transactions;

use App\Enums\TransactionStatus;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Policies\MembershipTransactionPolicy;

class TransactionPagesAndAccessTest extends TransactionTestCase
{
    public function test_directory_lists_transactions_with_member_amount_balance_and_status(): void
    {
        $member = $this->pendingMember(['first_name' => 'Rosa', 'last_name' => 'Mendoza']);
        $transaction = $this->application($member);

        $this->actingAs($this->admin)->get(route('admin.transactions.index'))
            ->assertOk()
            ->assertSee($transaction->reference_no)
            ->assertSee('Rosa')
            ->assertSee('Mendoza')
            ->assertSee($member->membership_no)
            ->assertSee('₱2,000.00')
            ->assertSee('Pending');
    }

    public function test_directory_search_and_filters(): void
    {
        $rosa = $this->application($this->pendingMember(['first_name' => 'Rosa', 'last_name' => 'Mendoza']));
        $ben = $this->markFullyPaid($this->application($this->pendingMember(['first_name' => 'Ben', 'last_name' => 'Garcia'])));
        $renewal = $this->service->openRenewal($this->expiredMember(), $this->gold, $this->admin);

        $get = fn (array $q) => $this->actingAs($this->admin)->get(route('admin.transactions.index', $q))->viewData('transactions')->pluck('id')->all();

        $this->assertSame([$rosa->id], $get(['q' => 'Mendoza']));
        $this->assertSame([$ben->id], $get(['q' => $ben->reference_no]));
        $this->assertSame([$ben->id], $get(['status' => 'paid']));
        $this->assertSame([$renewal->id], $get(['type' => 'renewal']));
        $this->assertCount(3, $get(['from' => '2026-11-12', 'to' => '2026-11-12']));
        $this->assertCount(0, $get(['from' => '2026-11-13']));
        $this->assertCount(3, $get(['status' => 'bogus', 'from' => 'not-a-date'])); // invalid filters ignored

        $this->actingAs($this->admin)->get(route('admin.transactions.index', ['q' => 'nobody']))->assertSee('No transactions match your filters');
    }

    public function test_directory_is_paginated(): void
    {
        foreach (range(1, 17) as $i) {
            $this->application();
        }

        $this->actingAs($this->admin)->get(route('admin.transactions.index'))->assertSee('page=2', false);
        $this->assertCount(2, $this->actingAs($this->admin)->get(route('admin.transactions.index', ['page' => 2]))->viewData('transactions'));
    }

    public function test_details_page_shows_amounts_balance_dates_and_member(): void
    {
        $member = $this->pendingMember(['first_name' => 'Carla']);
        $transaction = $this->existingTransaction($member, TransactionStatus::Pending, ['amount_paid' => '750.50']);
        Payment::factory()->for($transaction, 'transaction')->create(['amount' => '750.50', 'receipt_no' => 'OR-2026-000001']);

        $this->actingAs($this->admin)->get(route('admin.transactions.show', $transaction))
            ->assertOk()
            ->assertSee('Amount due')->assertSee('₱2,000.00')
            ->assertSee('₱750.50')
            ->assertSee('₱1,249.50') // balance
            ->assertSee('Gold')
            ->assertSee('12 months')
            ->assertSee('Set when approved')
            ->assertSee('Carla')
            ->assertSee('OR-2026-000001')
            ->assertSee('Payments have been recorded on this transaction, so it cannot be cancelled.');
    }

    public function test_member_transaction_history_page(): void
    {
        $member = $this->pendingMember();
        $cancelled = $this->application($member);
        $this->service->cancel($cancelled, $this->admin, 'Wrong level');
        $approved = $this->approvedApplicationFor($member->fresh());

        $response = $this->actingAs($this->admin)->get(route('admin.members.history', $member))->assertOk();

        $response->assertSeeInOrder([$approved->reference_no, $cancelled->reference_no])
            ->assertSee('Nov 12, 2026 – Nov 11, 2027')
            ->assertSee('Cancelled: Wrong level');

        $totals = $response->viewData('totals');
        $this->assertSame(200000, $totals['billed']); // cancelled transaction excluded
        $this->assertSame(200000, $totals['paid']);
        $this->assertSame(0, $totals['balance']);

        $this->actingAs($this->admin)->get(route('admin.members.show', $member))
            ->assertSee(route('admin.members.history', $member))
            ->assertSee(route('admin.transactions.show', $approved));
    }

    public function test_history_empty_state(): void
    {
        $this->actingAs($this->admin)->get(route('admin.members.history', $this->pendingMember()))
            ->assertOk()->assertSee('No transactions yet');
    }

    public function test_staff_and_guests_cannot_access_transactions(): void
    {
        $staff = User::factory()->member()->create();
        $transaction = $this->markFullyPaid($this->application());
        $member = $transaction->member;

        $requests = [
            ['get', route('admin.transactions.index'), []],
            ['get', route('admin.transactions.create'), []],
            ['post', route('admin.transactions.store'), ['member_id' => $member->id, 'type' => 'application']],
            ['get', route('admin.transactions.show', $transaction), []],
            ['patch', route('admin.transactions.approve', $transaction), []],
            ['patch', route('admin.transactions.cancel', $transaction), ['cancellation_reason' => 'x']],
            ['post', route('admin.transactions.expire'), []],
            ['get', route('admin.members.history', $member), []],
        ];

        foreach ($requests as [$method, $url, $data]) {
            $this->actingAs($staff)->{$method}($url, $data)->assertForbidden();
        }

        auth()->logout();
        foreach ($requests as [$method, $url, $data]) {
            $this->{$method}($url, $data)->assertRedirect(route('login'));
        }

        $this->assertSame(TransactionStatus::Paid, $transaction->fresh()->status);
        $this->assertSame(1, MembershipTransaction::count());
    }

    public function test_policy_allows_only_active_administrators(): void
    {
        $policy = new MembershipTransactionPolicy;
        $transaction = $this->application();

        $this->assertTrue($policy->approve($this->admin, $transaction));
        $this->assertFalse($policy->approve(User::factory()->member()->create(), $transaction));
        $this->assertFalse($policy->approve(User::factory()->admin()->inactive()->create(), $transaction));
        $this->assertFalse($policy->cancel(User::factory()->member()->create(), $transaction));
        $this->assertFalse($policy->runExpiry(User::factory()->member()->create()));
    }

    public function test_status_and_amount_fields_cannot_be_injected_when_creating(): void
    {
        $member = $this->pendingMember();

        $this->actingAs($this->admin)->post(route('admin.transactions.store'), [
            'member_id' => $member->id, 'type' => 'application',
            'status' => 'approved', 'amount' => '1.00', 'amount_paid' => '2000.00', 'reference_no' => 'TXN-HACK',
        ]);

        $transaction = MembershipTransaction::sole();
        $this->assertSame(TransactionStatus::Pending, $transaction->status);
        $this->assertSame('2000.00', $transaction->amount);
        $this->assertSame('0.00', $transaction->amount_paid);
        $this->assertNotSame('TXN-HACK', $transaction->reference_no);
    }
}
