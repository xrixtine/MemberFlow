<?php

namespace Tests\Feature\Members;

use App\Enums\TaskStatus;
use App\Enums\TransactionStatus;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase R2 — a member sees ONLY their own membership, payments, receipts and tasks.
 * Every check uses direct URLs, as an attacker would.
 */
class MemberIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private Payment $alicePayment;

    private Payment $bobPayment;

    protected function setUp(): void
    {
        parent::setUp();

        $a = Member::factory()->active()->create(['first_name' => 'Alice', 'middle_name' => null, 'last_name' => 'Aquino', 'email' => 'alice@example.test', 'phone' => '09170000001']);
        $b = Member::factory()->active()->create(['first_name' => 'Bob', 'middle_name' => null, 'last_name' => 'Bautista', 'email' => 'bob@example.test', 'phone' => '09179999999']);
        $a->forceFill(['membership_no' => 'MF-2026-91001'])->save();
        $b->forceFill(['membership_no' => 'MF-2026-92002'])->save();
        $this->alice = User::factory()->member($a)->create();
        $this->bob = User::factory()->member($b)->create();

        $this->alicePayment = $this->paymentFor($a, '1234.50', 'OR-2026-911111', 'TXN-ALICE-0001');
        $this->bobPayment = $this->paymentFor($b, '8765.25', 'OR-2026-922222', 'TXN-BOB-0002');
    }

    private function paymentFor(Member $member, string $amount, string $receipt, string $reference): Payment
    {
        $txn = MembershipTransaction::factory()->for($member)->create(['amount' => '10000.00', 'amount_paid' => $amount, 'status' => TransactionStatus::Pending]);
        $txn->forceFill(['reference_no' => $reference])->save();
        $payment = Payment::factory()->for($txn, 'transaction')->create(['amount' => $amount]);
        $payment->forceFill(['receipt_no' => $receipt])->save();

        return $payment;
    }

    private function taskFor(User $user, array $attributes = []): Task
    {
        $task = Task::factory()->create(['assigned_to' => $user->id] + $attributes);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $user->id, 'assigned_by' => $task->created_by, 'assigned_at' => now()]);

        return $task;
    }

    // ---- Membership ------------------------------------------------------------

    public function test_membership_page_shows_only_my_record(): void
    {
        $this->actingAs($this->alice)->get(route('my.membership'))->assertOk()
            ->assertSee('Alice Aquino')->assertSee('MF-2026-91001')->assertSee('TXN-ALICE-0001')
            ->assertDontSee('Bautista')->assertDontSee('MF-2026-92002')->assertDontSee('TXN-BOB-0002')
            ->assertDontSee('bob@example.test')->assertDontSee('09179999999');
    }

    public function test_query_parameters_cannot_switch_to_another_member(): void
    {
        $bobMemberId = $this->bob->member_id;

        $this->actingAs($this->alice)->get(route('my.membership', ['member' => $bobMemberId, 'member_id' => $bobMemberId]))
            ->assertOk()->assertSee('Alice Aquino')->assertDontSee('Bautista');
        $this->get(route('my.payments.index', ['member_id' => $bobMemberId]))->assertOk()->assertDontSee('OR-2026-922222');
    }

    // ---- Payments and receipts ------------------------------------------------------

    public function test_payment_history_lists_only_my_payments(): void
    {
        $this->actingAs($this->alice)->get(route('my.payments.index'))->assertOk()
            ->assertSee('OR-2026-911111')->assertSee('₱1,234.50')
            ->assertDontSee('OR-2026-922222')->assertDontSee('8,765.25');
    }

    public function test_receipts_open_only_for_my_own_payments(): void
    {
        $this->actingAs($this->alice)->get(route('my.payments.receipt', $this->alicePayment))->assertOk()
            ->assertSee('OR-2026-911111')->assertSee('Alice Aquino');

        $this->get(route('my.payments.receipt', $this->bobPayment))->assertForbidden();
        $this->get('/my/payments/999999/receipt')->assertNotFound();
        $this->get(route('admin.payments.receipt', $this->alicePayment))->assertForbidden(); // admin route stays admin-only
    }

    public function test_voided_payment_receipt_is_marked_void(): void
    {
        $this->alicePayment->forceFill(['voided_at' => now(), 'voided_by' => $this->alicePayment->received_by, 'void_reason' => 'Duplicate entry'])->save();

        $this->actingAs($this->alice)->get(route('my.payments.receipt', $this->alicePayment))->assertOk()->assertSee('VOIDED');
        $this->get(route('my.payments.index'))->assertSee('Void');
    }

    public function test_dashboard_shows_only_my_figures_never_organisation_totals(): void
    {
        Payment::factory()->count(2)->create(['amount' => '777.00']);

        $this->actingAs($this->alice)->get(route('dashboard'))->assertOk()
            ->assertSee('₱1,234.50')                       // my total paid
            ->assertSee('₱8,765.50')                       // my balance (10,000.00 − 1,234.50)
            ->assertDontSee('Bautista')->assertDontSee('8,765.25')->assertDontSee('777.00')
            ->assertDontSee('Total revenue');
    }

    // ---- Tasks ------------------------------------------------------------------------

    public function test_member_sees_and_updates_only_their_own_tasks(): void
    {
        $mine = $this->taskFor($this->alice, ['title' => 'Alice duty roster']);
        $theirs = $this->taskFor($this->bob, ['title' => 'Bob inventory check']);

        $this->actingAs($this->alice)->get(route('my.tasks.index'))->assertOk()->assertSee('Alice duty roster')->assertDontSee('Bob inventory check');
        $this->get(route('dashboard'))->assertSee('Alice duty roster')->assertDontSee('Bob inventory check');
        $this->get(route('my.tasks.show', $mine))->assertOk();
        $this->get(route('my.tasks.show', $theirs))->assertForbidden();

        $this->post(route('my.tasks.progress', $theirs), ['status' => 'in_progress', 'progress' => 50])->assertForbidden();
        $this->assertSame(0, $theirs->fresh()->progress);

        $this->post(route('my.tasks.progress', $mine), ['status' => 'in_progress', 'progress' => 40, 'note' => 'Started'])
            ->assertRedirect(route('my.tasks.show', $mine));
        $this->assertSame(40, $mine->fresh()->progress);
        $this->assertSame(TaskStatus::InProgress, $mine->fresh()->status);
    }

    public function test_previous_assignee_member_has_read_only_access(): void
    {
        $task = $this->taskFor($this->alice, ['title' => 'Handed over task']);
        TaskAssignment::where('task_id', $task->id)->update(['unassigned_at' => now()]);
        $task->forceFill(['assigned_to' => $this->bob->id])->save();

        $this->actingAs($this->alice)->get(route('my.tasks.show', $task))->assertOk();
        $this->post(route('my.tasks.progress', $task), ['status' => 'in_progress', 'progress' => 10])->assertForbidden();
        $this->assertSame(0, $task->fresh()->progress);
    }

    public function test_member_cannot_create_assign_or_manage_tasks(): void
    {
        $task = $this->taskFor($this->alice);
        $this->actingAs($this->alice);

        $this->post(route('admin.tasks.store'), ['title' => 'Self-made', 'priority' => 'low', 'assigned_to' => $this->bob->id])->assertForbidden();
        $this->patch(route('admin.tasks.reassign', $task), ['assigned_to' => $this->bob->id])->assertForbidden();
        $this->put(route('admin.tasks.update', $task), ['title' => 'Renamed', 'priority' => 'high'])->assertForbidden();
        $this->patch(route('admin.tasks.archive', $task))->assertForbidden();
        $this->delete(route('admin.tasks.destroy', $task))->assertForbidden();

        $this->assertSame(1, Task::count());
        $this->assertSame($this->alice->id, $task->fresh()->assigned_to);
        $this->assertFalse($this->alice->can('create', Task::class));
        $this->assertFalse($this->alice->can('assign', $task));
    }

    // ---- Account profile -------------------------------------------------------------

    public function test_member_cannot_change_profile_details_or_escalate(): void
    {
        $before = $this->alice->only(['name', 'email']);

        $this->actingAs($this->alice)->get('/profile')->assertOk()->assertSee('contact the membership office')->assertDontSee('Save profile');
        $this->put('/profile', ['name' => 'Hacker', 'email' => 'hacker@example.test', 'role' => 'admin', 'member_id' => $this->bob->member_id])
            ->assertForbidden();

        $fresh = $this->alice->fresh();
        $this->assertSame($before, $fresh->only(['name', 'email']));
        $this->assertSame(UserRole::Member, $fresh->role);
        $this->assertSame($this->alice->member_id, $fresh->member_id);
    }

    public function test_member_can_change_their_own_password(): void
    {
        $this->actingAs($this->alice)->put(route('profile.password'), [
            'current_password' => 'Password123', 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456',
        ])->assertRedirect(route('profile.edit'))->assertSessionHas('success');

        $this->post('/logout');
        $this->post('/login', ['email' => $this->alice->email, 'password' => 'NewSecret456'])->assertRedirect(route('dashboard'));
    }
}
