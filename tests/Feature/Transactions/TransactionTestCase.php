<?php

namespace Tests\Feature\Transactions;

use App\Enums\MemberStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\MembershipLevel;
use App\Models\MembershipTransaction;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared fixtures for transaction tests.
 */
abstract class TransactionTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected MembershipCategory $category;

    protected MembershipLevel $gold;

    protected MembershipLevel $silver;

    protected MembershipService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(10, 0));

        $this->admin = User::factory()->admin()->create();
        $this->category = MembershipCategory::factory()->create(['name' => 'Regular']);
        $this->gold = MembershipLevel::factory()->create(['name' => 'Gold', 'fee' => '2000.00', 'validity_months' => 12]);
        $this->silver = MembershipLevel::factory()->create(['name' => 'Silver', 'fee' => '1000.00', 'validity_months' => 6]);
        $this->service = app(MembershipService::class);
    }

    protected function pendingMember(array $attributes = []): Member
    {
        return Member::factory()->for($this->category, 'category')->for($this->gold, 'level')->create($attributes);
    }

    /** An active member whose membership ends on the given date. */
    protected function activeMember(string $end, array $attributes = []): Member
    {
        $member = $this->pendingMember($attributes);
        $member->forceFill([
            'status' => MemberStatus::Active,
            'membership_start' => '2025-11-12',
            'membership_end' => $end,
        ])->save();

        return $member->fresh();
    }

    protected function expiredMember(): Member
    {
        $member = $this->pendingMember();
        $member->forceFill([
            'status' => MemberStatus::Expired, 'membership_start' => '2024-06-01', 'membership_end' => '2025-05-31',
        ])->save();

        return $member->fresh();
    }

    /**
     * CONTROLLED TEST FIXTURE: puts a transaction into the state PaymentService
     * (Phase 5) produces once payments cover the full amount. There is
     * intentionally no application route or admin action that does this.
     */
    protected function markFullyPaid(MembershipTransaction $transaction): MembershipTransaction
    {
        $transaction->forceFill(['amount_paid' => $transaction->amount, 'status' => TransactionStatus::Paid])->save();

        return $transaction->fresh();
    }

    protected function application(?Member $member = null): MembershipTransaction
    {
        return $this->service->openApplication($member ?? $this->pendingMember(), $this->admin);
    }

    protected function approvedApplicationFor(Member $member): MembershipTransaction
    {
        $transaction = $this->markFullyPaid($this->application($member));

        return $this->service->approve($transaction, $this->admin);
    }

    protected function existingTransaction(Member $member, TransactionStatus $status, array $attributes = []): MembershipTransaction
    {
        return MembershipTransaction::factory()->for($member)->for($this->gold, 'level')->create(array_merge([
            'type' => TransactionType::Application, 'status' => $status, 'amount' => '2000.00',
        ], $attributes));
    }
}
