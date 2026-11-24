<?php

namespace Tests\Feature\Tasks;

use App\Enums\MemberStatus;
use App\Enums\TaskStatus;
use App\Exceptions\TaskException;
use App\Models\Member;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskProgressUpdate;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskReassignedAway;
use App\Notifications\TaskStatusNotification;
use App\Services\TaskService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Email\EmailTestCase;

/**
 * Phase R4 — tasks for member accounts: eligibility, assignment, progress, notifications,
 * isolation, and transitional staff compatibility. Emails use the in-memory mailer.
 */
class MemberTaskTest extends EmailTestCase
{
    private TaskService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TaskService::class);
    }

    /** A member account whose member record has the given attributes (default: active, current). */
    private function memberAccount(array $memberAttributes = [], array $userAttributes = []): User
    {
        $member = Member::factory()->active()->for($this->category, 'category')->for($this->gold, 'level')
            ->create(['email' => self::email('m.'.Str::lower(Str::random(6))), 'middle_name' => null] + $memberAttributes);
        $member->forceFill(['membership_no' => 'MF-2026-'.random_int(10000, 99999)])->save();

        return User::factory()->member($member)->create($userAttributes);
    }

    private function createTaskFor(User $assignee, array $extra = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.tasks.store'), [
            'title' => 'Prepare welcome kits', 'priority' => 'high', 'due_date' => today()->addDays(3)->format('Y-m-d'), 'assigned_to' => $assignee->id,
        ] + $extra);
    }

    // ---- Eligibility ----------------------------------------------------------------------

    public function test_admin_assigns_a_task_to_an_eligible_member(): void
    {
        $alice = $this->memberAccount(['first_name' => 'Alice']);

        $this->actingAs($this->admin)->get(route('admin.tasks.create'))->assertOk()
            ->assertSee($alice->name.' — '.$alice->member->membership_no.' · Gold · active member account');
        $this->createTaskFor($alice)->assertSessionHasNoErrors()->assertRedirect();

        $task = Task::sole();
        $this->assertSame($alice->id, $task->assigned_to);
        $this->assertSame(1, TaskAssignment::where('task_id', $task->id)->where('user_id', $alice->id)->count());
        $this->assertDatabaseHas('task_progress_updates', ['task_id' => $task->id, 'type' => TaskProgressUpdate::ASSIGNED]);
    }

    public function test_membership_ending_today_is_still_eligible(): void
    {
        $member = $this->memberAccount(['membership_end' => today()]);

        $this->assertNull($this->service->assignmentBlocker($member));
        $this->createTaskFor($member)->assertSessionHasNoErrors();
    }

    public function test_ineligible_accounts_are_refused_on_the_server_and_not_listed(): void
    {
        $deletedMember = $this->memberAccount();
        $deletedMember->member->delete();

        $cases = [
            'pending membership' => $this->memberAccount(['status' => MemberStatus::Pending, 'membership_start' => null, 'membership_end' => null]),
            'expired status' => $this->memberAccount(['status' => MemberStatus::Expired, 'membership_end' => today()->subDay()]),
            'active status but end date passed' => $this->memberAccount(['membership_end' => today()->subDay()]),
            'deactivated member record' => $this->memberAccount(['status' => MemberStatus::Inactive]),
            'deleted member record' => $deletedMember,
            'deactivated login account' => $this->memberAccount([], ['is_active' => false]),
            'administrator' => User::factory()->admin()->create(),
            'retired staff' => User::factory()->legacyStaff()->create(['is_active' => true]),
        ];

        $form = $this->actingAs($this->admin)->get(route('admin.tasks.create'))->assertOk();
        foreach ($cases as $label => $user) {
            $form->assertDontSee(e($user->name).' —', false);
            $this->createTaskFor($user)->assertSessionHasErrors('assigned_to');
            $this->assertNotNull($this->service->assignmentBlocker($user->fresh()), $label);
            try {
                $this->service->create(['title' => 'Direct', 'priority' => 'low'], $user, $this->admin);
                $this->fail("Service assigned a task to: {$label}");
            } catch (TaskException $e) {
                $this->assertSame('assigned_to', $e->field);
            }
        }
        $this->assertSame(0, Task::count());
    }

    public function test_eligibility_is_rechecked_when_the_form_is_submitted(): void
    {
        $bob = $this->memberAccount();
        $this->actingAs($this->admin)->get(route('admin.tasks.create'))->assertSee($bob->name);

        $bob->member->forceFill(['status' => MemberStatus::Inactive])->save(); // deactivated meanwhile

        $this->createTaskFor($bob)->assertSessionHasErrors('assigned_to');
        $this->assertSame(0, Task::count());
    }

    // ---- Progress, completion, locking ------------------------------------------------------

    public function test_member_updates_progress_adds_notes_and_completes_their_task(): void
    {
        $alice = $this->memberAccount();
        $task = $this->service->create(['title' => 'Count chairs', 'priority' => 'low'], $alice, $this->admin);
        Notification::fake();

        $this->actingAs($alice)->get(route('my.tasks.show', $task))->assertOk()->assertSee('Update progress');
        $this->post(route('my.tasks.progress', $task), ['status' => 'in_progress', 'progress' => 30, 'note' => 'Half the hall done'])->assertRedirect();
        $this->post(route('my.tasks.progress', $task), ['status' => 'on_hold', 'progress' => 30, 'note' => 'Waiting for keys'])->assertRedirect();
        $this->post(route('my.tasks.progress', $task), ['status' => 'completed', 'progress' => 80])->assertRedirect();

        $task->refresh();
        $this->assertSame([TaskStatus::Completed, 100], [$task->status, $task->progress]);
        $this->assertNotNull($task->completed_at);
        $this->assertSame(['status', 'status', 'completed'], $task->updates()->where('user_id', $alice->id)->orderBy('id')->pluck('type')->all());
        Notification::assertSentTo($this->admin, TaskStatusNotification::class, 2);      // on hold + completed

        // Completed → locked until an administrator reopens it.
        $this->post(route('my.tasks.progress', $task), ['status' => 'in_progress', 'progress' => 50])->assertForbidden();
        $this->actingAs($this->admin)->patch(route('admin.tasks.reopen', $task), ['progress' => 60, 'reason' => 'Missed a room']);
        $this->actingAs($alice)->post(route('my.tasks.progress', $task), ['status' => 'in_progress', 'progress' => 70])->assertRedirect();
        $this->assertSame(70, $task->fresh()->progress);
        $this->get(route('my.history'))->assertOk()->assertSee('Count chairs');
    }

    public function test_archived_tasks_cannot_be_updated(): void
    {
        $alice = $this->memberAccount();
        $task = $this->service->create(['title' => 'Archive me', 'priority' => 'low'], $alice, $this->admin);
        $this->service->archive($task, $this->admin);

        $this->actingAs($alice)->post(route('my.tasks.progress', $task), ['status' => 'in_progress', 'progress' => 10])->assertForbidden();
        $this->get(route('my.tasks.show', $task))->assertOk()->assertSee('archived');
    }

    public function test_progress_form_cannot_change_protected_task_fields(): void
    {
        $alice = $this->memberAccount();
        $bob = $this->memberAccount();
        $task = $this->service->create(['title' => 'Protected', 'priority' => 'low'], $alice, $this->admin);

        $this->actingAs($alice)->post(route('my.tasks.progress', $task), [
            'status' => 'in_progress', 'progress' => 20, 'assigned_to' => $bob->id, 'title' => 'Hacked', 'priority' => 'urgent',
            'created_by' => $alice->id, 'archived_at' => now()->toDateTimeString(),
        ])->assertRedirect();

        $task->refresh();
        $this->assertSame([$alice->id, 'Protected', $this->admin->id, null], [$task->assigned_to, $task->title, $task->created_by, $task->archived_at]);
    }

    // ---- Reassignment and history -------------------------------------------------------------

    public function test_reassignment_between_members_keeps_history_and_read_only_access(): void
    {
        $alice = $this->memberAccount(['first_name' => 'Alice']);
        $bob = $this->memberAccount(['first_name' => 'Bob']);
        $task = $this->service->create(['title' => 'Hand-over task', 'priority' => 'medium'], $alice, $this->admin);
        $this->service->recordProgress($task, $alice, ['status' => 'in_progress', 'progress' => 40, 'note' => 'Started']);
        Notification::fake();

        $this->actingAs($this->admin)->get(route('admin.tasks.show', $task))->assertSee($bob->name.' — ');
        $this->patch(route('admin.tasks.reassign', $task), ['assigned_to' => $bob->id])->assertSessionHas('success');

        Notification::assertSentTo($bob, TaskAssignedNotification::class);
        Notification::assertSentTo($alice, TaskReassignedAway::class);

        $this->assertSame(2, TaskAssignment::where('task_id', $task->id)->count());
        $this->assertNotNull(TaskAssignment::where('task_id', $task->id)->where('user_id', $alice->id)->value('unassigned_at'));
        $this->assertSame(1, TaskProgressUpdate::where('task_id', $task->id)->where('user_id', $alice->id)->count()); // Alice's note kept

        $this->actingAs($alice)->get(route('my.tasks.show', $task))->assertOk()->assertSee('Read-only');
        $this->post(route('my.tasks.progress', $task), ['status' => 'in_progress', 'progress' => 90])->assertForbidden();
        $this->get(route('my.tasks.index', ['tab' => 'previous']))->assertSee('Hand-over task');
        $this->actingAs($bob)->post(route('my.tasks.progress', $task), ['status' => 'in_progress', 'progress' => 55])->assertRedirect();
        $this->assertSame(55, $task->fresh()->progress);
    }

    public function test_reassignment_to_an_ineligible_member_is_refused(): void
    {
        $alice = $this->memberAccount();
        $expired = $this->memberAccount(['status' => MemberStatus::Expired, 'membership_end' => today()->subWeek()]);
        $task = $this->service->create(['title' => 'Stay put', 'priority' => 'low'], $alice, $this->admin);

        $this->actingAs($this->admin)->patch(route('admin.tasks.reassign', $task), ['assigned_to' => $expired->id])->assertSessionHasErrors('assigned_to');
        $this->assertSame($alice->id, $task->fresh()->assigned_to);
        $this->assertSame(1, TaskAssignment::where('task_id', $task->id)->count());
    }

    public function test_members_cannot_assign_or_reassign_tasks(): void
    {
        $alice = $this->memberAccount();
        $bob = $this->memberAccount();
        $task = $this->service->create(['title' => 'Mine', 'priority' => 'low'], $alice, $this->admin);

        $this->actingAs($alice)->patch(route('admin.tasks.reassign', $task), ['assigned_to' => $bob->id])->assertForbidden();
        $this->post(route('admin.tasks.store'), ['title' => 'x', 'priority' => 'low', 'assigned_to' => $bob->id])->assertForbidden();
        $this->actingAs($bob)->post(route('my.tasks.progress', $task), ['status' => 'in_progress', 'progress' => 10])->assertForbidden();

        $this->assertSame($alice->id, $task->fresh()->assigned_to);
        $this->assertSame(1, Task::count());
    }

    // ---- Notifications ------------------------------------------------------------------------

    public function test_assignment_email_goes_to_the_member_with_app_url_links_and_no_private_details(): void
    {
        config(['app.url' => 'http://192.168.50.10:8000']);
        $alice = $this->memberAccount(['first_name' => 'Alice']);
        $related = Member::factory()->for($this->category, 'category')->for($this->gold, 'level')
            ->create(['first_name' => 'Rita', 'middle_name' => null, 'last_name' => 'Related', 'email' => self::email('rita.private'), 'phone' => '09171234567', 'address' => '99 Secret St']);

        $this->createTaskFor($alice, ['member_id' => $related->id])->assertSessionHasNoErrors();

        $mail = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $html = (string) $mail->getHtmlBody();
        $this->assertSame($alice->email, $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('http://192.168.50.10:8000/my/tasks/', $html);
        $this->assertStringNotContainsString('127.0.0.1', $html);
        foreach (['rita.private', '09171234567', '99 Secret St', 'Related', '₱'] as $private) {
            $this->assertStringNotContainsString($private, $html);
        }
    }

    public function test_no_assignment_email_to_an_account_that_can_no_longer_sign_in(): void
    {
        $alice = $this->memberAccount();
        $task = $this->service->create(['title' => 'Quiet', 'priority' => 'low'], $alice, $this->admin);
        $bob = $this->memberAccount();
        $alice->member->forceFill(['status' => MemberStatus::Inactive])->save();
        Notification::fake();

        $this->service->reassign($task, $bob, $this->admin);

        Notification::assertSentTo($bob, TaskAssignedNotification::class);
        Notification::assertNotSentTo($alice, TaskReassignedAway::class);  // her member record is deactivated
    }

    // ---- Data isolation -------------------------------------------------------------------------

    public function test_assignee_sees_only_the_related_members_name_and_gains_no_access_to_their_records(): void
    {
        $alice = $this->memberAccount();
        $related = Member::factory()->active()->for($this->category, 'category')->for($this->gold, 'level')
            ->create(['first_name' => 'Rita', 'middle_name' => null, 'last_name' => 'Related', 'email' => self::email('rita.iso'), 'phone' => '09170000042']);
        $related->forceFill(['membership_no' => 'MF-2026-77777'])->save();
        $txn = MembershipTransaction::factory()->for($related)->create();
        $payment = Payment::factory()->for($txn, 'transaction')->create(['amount' => '321.00']);
        $task = $this->service->create(['title' => 'Call Rita about documents', 'priority' => 'low', 'member_id' => $related->id], $alice, $this->admin);

        $this->actingAs($alice)->get(route('my.tasks.show', $task))->assertOk()
            ->assertSee('Rita Related')
            ->assertDontSee('rita.iso')->assertDontSee('09170000042')->assertDontSee('MF-2026-77777')->assertDontSee('321.00')
            ->assertDontSee(route('admin.members.show', $related));

        $this->get(route('admin.members.show', $related))->assertForbidden();
        $this->get(route('my.payments.receipt', $payment))->assertForbidden();
        $this->get(route('my.membership'))->assertDontSee('Rita');
    }

    // ---- Transitional staff -----------------------------------------------------------------------

    public function test_staff_cannot_receive_tasks_and_historical_staff_tasks_can_be_handed_to_members(): void
    {
        $staff = User::factory()->staff()->create(['name' => 'Sam Oldstaff', 'email' => self::email('sam.staff')]);
        $alice = $this->memberAccount();

        // The retired role is never offered and is refused on the server.
        $this->actingAs($this->admin)->get(route('admin.tasks.create'))->assertDontSee('Sam Oldstaff');
        $this->createTaskFor($staff)->assertSessionHasErrors('assigned_to');
        $this->assertSame('Tasks can only be assigned to member accounts.', $this->service->assignmentBlocker($staff));

        // A task held by a staff account before R6 (history), with its progress entry.
        $old = Task::factory()->create(['title' => 'Staff era job', 'assigned_to' => $staff->id, 'created_by' => $this->admin->id]);
        TaskAssignment::create(['task_id' => $old->id, 'user_id' => $staff->id, 'assigned_by' => $this->admin->id, 'assigned_at' => now()->subWeek()]);
        TaskProgressUpdate::create(['task_id' => $old->id, 'user_id' => $staff->id, 'type' => 'progress', 'old_progress' => 0, 'new_progress' => 25]);

        $this->actingAs($this->admin)->get(route('admin.tasks.show', $old))->assertOk()
            ->assertSee('The assignee can no longer work on this task.');
        $this->patch(route('admin.tasks.reassign', $old), ['assigned_to' => $alice->id])->assertSessionHas('success');

        $this->assertSame(2, TaskAssignment::where('task_id', $old->id)->count());        // staff period kept
        $this->assertSame(1, TaskProgressUpdate::where('task_id', $old->id)->where('user_id', $staff->id)->count());
        $this->actingAs($alice)->post(route('my.tasks.progress', $old), ['status' => 'in_progress', 'progress' => 50])->assertRedirect();
        $this->assertSame(50, $old->fresh()->progress);
    }

    public function test_workload_report_lists_member_assignees(): void
    {
        $alice = $this->memberAccount(['first_name' => 'Alice']);
        $this->service->create(['title' => 'Report me', 'priority' => 'low'], $alice, $this->admin);
        $idle = $this->memberAccount(['first_name' => 'Idle']);

        $this->actingAs($this->admin)->get(route('admin.reports.index'))->assertOk()
            ->assertSee('Task workload and progress')->assertSee($alice->name)->assertSee($alice->member->membership_no)->assertDontSee($idle->name);
        $this->get(route('admin.tasks.index'))->assertSee($alice->name);
    }
}
