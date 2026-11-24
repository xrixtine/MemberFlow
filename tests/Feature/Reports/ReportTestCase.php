<?php

namespace Tests\Feature\Reports;

use App\Enums\MemberStatus;
use App\Enums\PaymentMethod;
use App\Enums\TaskStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\MembershipLevel;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A small, fully known data set so every dashboard/report figure can be checked by hand.
 *
 * Members (by current status): 2 active, 1 pending, 1 expired, 1 inactive, + 1 soft-deleted (excluded).
 *   Regular: Ana (active, Gold), Ben (pending, Silver), Dee (inactive, Silver)
 *   Student: Cy (expired, Silver), Eve (active, Gold)
 *
 * Transactions (all opened 2026-11-05 unless noted):
 *   T1 Ana  application Gold   2000 approved  paid 2000
 *   T2 Ben  application Silver 1000 pending   paid  300   → balance 700
 *   T3 Cy   renewal     Silver 1000 pending   paid    0   → balance 1000
 *   T4 Dee  application Silver 1000 cancelled paid    0   → excluded from billed/outstanding
 *   T5 Eve  renewal     Gold   2000 paid      paid 2000   → awaiting approval, balance 0
 *   T6 Eve  application Gold   2000 approved  paid 2000   opened 2026-09-01 (outside the default period)
 *
 * Payments (paid_at):
 *   P1 T1 2000 cash   2026-11-05 · P2 T2 300 gcash 2026-11-06 · P3 T5 2000 bank 2026-11-07
 *   P4 T2 500 cash    2026-11-06 VOIDED (excluded) · P5 T6 2000 cash 2026-09-01 (outside period)
 */
abstract class ReportTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $ana;   // member account (AnaMember)

    protected User $ben;   // member account (EveMember)

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(10, 0));

        $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);

        $regular = MembershipCategory::factory()->create(['name' => 'Regular']);
        $student = MembershipCategory::factory()->create(['name' => 'Student']);
        MembershipCategory::factory()->create(['name' => 'Corporate']); // no members
        $gold = MembershipLevel::factory()->create(['name' => 'Gold', 'fee' => '2000.00', 'sort_order' => 2]);
        $silver = MembershipLevel::factory()->create(['name' => 'Silver', 'fee' => '1000.00', 'sort_order' => 1]);

        $member = fn ($cat, $lvl, MemberStatus $status, string $name) => Member::factory()->for($cat, 'category')->for($lvl, 'level')
            ->create(['first_name' => $name, 'status' => $status]);

        $ana = $member($regular, $gold, MemberStatus::Active, 'AnaMember');
        $ben = $member($regular, $silver, MemberStatus::Pending, 'BenMember');
        $dee = $member($regular, $silver, MemberStatus::Inactive, 'DeeMember');
        $cy = $member($student, $silver, MemberStatus::Expired, 'CyMember');
        $eve = $member($student, $gold, MemberStatus::Active, 'EveMember');
        $member($student, $gold, MemberStatus::Active, 'Deleted')->delete();

        // The two task workers are member accounts linked to existing (active) fixture members,
        // so they add no extra member records to the counts above.
        $this->ana = User::factory()->member($ana)->create(['name' => 'Ana Worker']);
        $this->ben = User::factory()->member($eve)->create(['name' => 'Ben Worker']);

        $txn = function (Member $m, MembershipLevel $lvl, TransactionType $type, TransactionStatus $status, string $amount, string $paid, string $date = '2026-11-05 09:00:00') {
            $t = MembershipTransaction::factory()->for($m)->for($lvl, 'level')->create([
                'type' => $type, 'status' => $status, 'amount' => $amount, 'amount_paid' => $paid,
            ]);
            $t->forceFill(['created_at' => $date])->save();

            return $t;
        };

        $t1 = $txn($ana, $gold, TransactionType::Application, TransactionStatus::Approved, '2000.00', '2000.00');
        $t2 = $txn($ben, $silver, TransactionType::Application, TransactionStatus::Pending, '1000.00', '300.00');
        $txn($cy, $silver, TransactionType::Renewal, TransactionStatus::Pending, '1000.00', '0.00');
        $txn($dee, $silver, TransactionType::Application, TransactionStatus::Cancelled, '1000.00', '0.00');
        $t5 = $txn($eve, $gold, TransactionType::Renewal, TransactionStatus::Paid, '2000.00', '2000.00');
        $t6 = $txn($eve, $gold, TransactionType::Application, TransactionStatus::Approved, '2000.00', '2000.00', '2026-09-01 09:00:00');

        $pay = fn ($t, string $amount, PaymentMethod $method, string $at, bool $voided = false) => Payment::factory()->for($t, 'transaction')->create([
            'amount' => $amount, 'method' => $method, 'paid_at' => $at, 'voided_at' => $voided ? $at : null,
        ]);
        $pay($t1, '2000.00', PaymentMethod::Cash, '2026-11-05 10:00:00');
        $pay($t2, '300.00', PaymentMethod::GCash, '2026-11-06 10:00:00');
        $pay($t5, '2000.00', PaymentMethod::BankTransfer, '2026-11-07 10:00:00');
        $pay($t2, '500.00', PaymentMethod::Cash, '2026-11-06 11:00:00', true);
        $pay($t6, '2000.00', PaymentMethod::Cash, '2026-09-01 10:00:00');

        // Tasks: Ana has 1 todo (overdue), 1 in progress (60%), 1 on hold (20%), 1 completed in period (on time);
        // Ben has 1 completed in period (late); 1 archived task (excluded from status counts).
        $task = fn (User $u, array $a) => Task::factory()->create(array_merge(['assigned_to' => $u->id, 'created_by' => $this->admin->id], $a));
        $task($this->ana, ['title' => 'Ana overdue', 'status' => TaskStatus::Todo, 'progress' => 0, 'due_date' => '2026-11-10']);
        $task($this->ana, ['title' => 'Ana working', 'status' => TaskStatus::InProgress, 'progress' => 60, 'due_date' => '2026-11-20']);
        $task($this->ana, ['title' => 'Ana paused', 'status' => TaskStatus::OnHold, 'progress' => 20, 'due_date' => null]);
        $task($this->ana, ['title' => 'Ana done', 'status' => TaskStatus::Completed, 'progress' => 100, 'due_date' => '2026-11-10', 'completed_at' => '2026-11-09 15:00:00']);
        $task($this->ben, ['title' => 'Ben late done', 'status' => TaskStatus::Completed, 'progress' => 100, 'due_date' => '2026-11-03', 'completed_at' => '2026-11-04 15:00:00']);
        $task($this->ben, ['title' => 'Ben archived', 'status' => TaskStatus::Todo, 'progress' => 0, 'archived_at' => now(), 'archived_by' => $this->admin->id]);
    }
}
