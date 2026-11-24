<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\MembershipLevel;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Database-level guarantees (constraints that hold even if application code is bypassed).
 */
class SchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_memberflow_tables_exist(): void
    {
        foreach ([
            'users', 'user_invitations', 'membership_categories', 'membership_levels', 'members',
            'membership_transactions', 'payments', 'tasks', 'task_assignments', 'task_progress_updates',
            'email_deliveries', 'notifications', 'jobs', 'failed_jobs', 'sessions', 'cache',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}]");
        }
    }

    public function test_level_fee_cannot_be_negative(): void
    {
        $this->expectException(QueryException::class);
        MembershipLevel::factory()->create(['fee' => '-1.00']);
    }

    public function test_transaction_amount_paid_cannot_exceed_amount(): void
    {
        $this->expectException(QueryException::class);
        MembershipTransaction::factory()->create(['amount' => '1000.00', 'amount_paid' => '1000.01']);
    }

    public function test_payment_amount_must_be_positive(): void
    {
        $this->expectException(QueryException::class);
        Payment::factory()->create(['amount' => '0.00']);
    }

    public function test_task_progress_cannot_exceed_100(): void
    {
        $this->expectException(QueryException::class);
        Task::factory()->create(['progress' => 101]);
    }

    public function test_membership_numbers_are_unique(): void
    {
        Member::factory()->create()->forceFill(['membership_no' => 'MF-2026-00001'])->save();

        $this->expectException(UniqueConstraintViolationException::class);
        Member::factory()->create()->forceFill(['membership_no' => 'MF-2026-00001'])->save();
    }

    public function test_member_emails_are_unique(): void
    {
        Member::factory()->create(['email' => 'same@example.test']);

        $this->expectException(UniqueConstraintViolationException::class);
        Member::factory()->create(['email' => 'same@example.test']);
    }

    public function test_payment_idempotency_keys_are_unique(): void
    {
        $payment = Payment::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        Payment::factory()->create(['idempotency_key' => $payment->idempotency_key]);
    }

    public function test_category_in_use_cannot_be_deleted(): void
    {
        $member = Member::factory()->create();

        $this->expectException(QueryException::class);
        MembershipCategory::whereKey($member->membership_category_id)->delete();
    }

    public function test_member_with_transactions_cannot_be_hard_deleted(): void
    {
        $transaction = MembershipTransaction::factory()->create();

        $this->expectException(QueryException::class);
        Member::whereKey($transaction->member_id)->forceDelete();
    }

    public function test_task_with_history_cannot_be_hard_deleted(): void
    {
        $task = Task::factory()->create();
        $task->updates()->create(['type' => 'created', 'user_id' => $task->created_by]);

        $this->expectException(QueryException::class);
        $task->forceDelete();
    }

    public function test_users_referenced_by_records_cannot_be_hard_deleted(): void
    {
        $payment = Payment::factory()->create();

        $this->expectException(QueryException::class);
        User::whereKey($payment->received_by)->delete();
    }

    /**
     * Regression (Phase 7b): MariaDB silently adds "ON UPDATE CURRENT_TIMESTAMP" to the
     * first non-nullable TIMESTAMP column, which rewrote assignment/invitation history.
     */
    public function test_no_column_is_silently_rewritten_on_update(): void
    {
        $columns = \DB::select(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE '%on update%'"
        );

        $this->assertSame([], array_map(fn ($c) => $c->TABLE_NAME.'.'.$c->COLUMN_NAME, $columns));
    }

    public function test_closing_an_assignment_keeps_its_original_start_time(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(9, 0));
        $admin = User::factory()->admin()->create();
        $ana = User::factory()->member()->create();
        $tasks = app(TaskService::class);

        $task = $tasks->create(['title' => 'History check'], $ana, $admin);
        $this->travelTo(now()->setDate(2030, 1, 1)->setTime(12, 0)); // far from the real clock
        $ben = User::factory()->member()->create(); // a membership that is current in 2030
        $tasks->reassign($task, $ben, $admin);

        $first = $task->assignments()->where('user_id', $ana->id)->sole();
        $this->assertSame('2026-11-12 09:00:00', $first->assigned_at->toDateTimeString());
        $this->assertSame('2030-01-01 12:00:00', $first->unassigned_at->toDateTimeString());
    }
}
