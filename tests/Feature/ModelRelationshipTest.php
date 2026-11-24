<?php

namespace Tests\Feature;

use App\Enums\MemberSource;
use App\Enums\MemberStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\MembershipLevel;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_belongs_to_exactly_one_category_and_level(): void
    {
        $category = MembershipCategory::factory()->create(['name' => 'Student']);
        $level = MembershipLevel::factory()->create(['name' => 'Gold']);
        $member = Member::factory()->for($category, 'category')->for($level, 'level')->create();

        $this->assertTrue($member->category->is($category));
        $this->assertTrue($member->level->is($level));
        $this->assertTrue($category->members->contains($member));
        $this->assertTrue($level->members->contains($member));
    }

    public function test_member_defaults_and_public_identifier(): void
    {
        $member = Member::factory()->create();

        $this->assertSame(26, strlen($member->public_id));
        $this->assertNotSame((string) $member->id, $member->public_id);
        $this->assertSame(MemberSource::WalkIn, $member->source);
        $this->assertSame(MemberStatus::Pending, $member->fresh()->status);
        $this->assertFalse($member->isEmailVerified());
    }

    public function test_member_transactions_and_payments(): void
    {
        $member = Member::factory()->create();
        $older = MembershipTransaction::factory()->for($member)->create([
            'status' => TransactionStatus::Approved, 'created_at' => now()->subYear(),
        ]);
        $open = MembershipTransaction::factory()->for($member)->create([
            'type' => TransactionType::Renewal, 'amount' => '1000.00', 'amount_paid' => '400.00',
        ]);
        Payment::factory()->for($open, 'transaction')->create(['amount' => '400.00']);

        $member->load(['transactions', 'payments', 'latestTransaction', 'openTransaction']);

        $this->assertCount(2, $member->transactions);
        $this->assertCount(1, $member->payments);
        $this->assertTrue($member->latestTransaction->is($open));
        $this->assertTrue($member->openTransaction->is($open));
        $this->assertSame(TransactionType::Renewal, $open->type);
        $this->assertSame(60000, $open->balanceCents());
        $this->assertSame('₱600.00', $open->formattedBalance());
        $this->assertTrue($older->fresh()->member->is($member));
    }

    public function test_transactions_keep_their_member_after_soft_delete(): void
    {
        $transaction = MembershipTransaction::factory()->create();
        $transaction->member->delete();

        $this->assertSoftDeleted('members', ['id' => $transaction->member_id]);
        $this->assertNotNull($transaction->fresh()->member);
    }

    public function test_valid_payments_exclude_voided_ones(): void
    {
        $transaction = MembershipTransaction::factory()->create();
        Payment::factory()->for($transaction, 'transaction')->create();
        Payment::factory()->for($transaction, 'transaction')->create(['voided_at' => now()]);

        $this->assertCount(2, $transaction->payments);
        $this->assertCount(1, $transaction->validPayments);
    }

    public function test_task_relationships_and_history(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->member()->create();
        $task = Task::factory()->create(['created_by' => $admin->id, 'assigned_to' => $staff->id]);

        TaskAssignment::create([
            'task_id' => $task->id, 'user_id' => $staff->id, 'assigned_by' => $admin->id, 'assigned_at' => now(),
        ]);
        $task->updates()->create(['user_id' => $admin->id, 'type' => 'created']);

        $task->load(['assignee', 'creator', 'assignments', 'updates']);

        $this->assertTrue($task->assignee->is($staff));
        $this->assertTrue($task->creator->is($admin));
        $this->assertCount(1, $task->assignments);
        $this->assertTrue($task->assignments->first()->isCurrent());
        $this->assertCount(1, $task->updates);
        $this->assertTrue($staff->assignedTasks()->first()->is($task));
    }

    public function test_task_scopes(): void
    {
        $overdue = Task::factory()->create(['due_date' => today()->subDay()]);
        Task::factory()->create(['due_date' => today()->subDay(), 'status' => 'completed', 'progress' => 100]);
        $archived = Task::factory()->create(['archived_at' => now()]);

        $this->assertSame([$overdue->id], Task::overdue()->pluck('id')->all());
        $this->assertTrue($overdue->isOverdue());
        $this->assertFalse(Task::active()->pluck('id')->contains($archived->id));
        $this->assertSame([$archived->id], Task::archived()->pluck('id')->all());
    }

    public function test_member_search_scope_matches_names_and_numbers(): void
    {
        $juan = Member::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
        $juan->forceFill(['membership_no' => 'MF-2026-00077'])->save();
        Member::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);

        $this->assertSame([$juan->id], Member::search('Juan Dela')->pluck('id')->all());
        $this->assertSame([$juan->id], Member::search('00077')->pluck('id')->all());
        $this->assertSame(2, Member::search('')->count());
        $this->assertSame(0, Member::search('%')->count());
    }
}
