<?php

namespace Tests\Feature\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Events\TaskAssigned;
use App\Models\Member;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskProgressUpdate;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;

class AdminTaskManagementTest extends TaskTestCase
{
    // ---- Create ------------------------------------------------------------------

    public function test_admin_creates_and_assigns_a_task(): void
    {
        Event::fake([TaskAssigned::class]);
        $member = Member::factory()->create();

        $this->actingAs($this->admin)->get(route('admin.tasks.create'))
            ->assertOk()->assertSee('Ana Staff')->assertSee('Ben Staff');

        $response = $this->actingAs($this->admin)->post(route('admin.tasks.store'), [
            'title' => 'Call expiring members', 'description' => 'Remind them about renewal.',
            'priority' => 'high', 'due_date' => '2026-11-30', 'assigned_to' => $this->ana->id, 'member_id' => $member->id,
        ]);

        $task = Task::sole();
        $response->assertRedirect(route('admin.tasks.show', $task))->assertSessionHas('success');

        $this->assertSame('Call expiring members', $task->title);
        $this->assertSame(TaskPriority::High, $task->priority);
        $this->assertSame(TaskStatus::Todo, $task->status);
        $this->assertSame(0, $task->progress);
        $this->assertSame('2026-11-30', $task->due_date->toDateString());
        $this->assertTrue($task->assignee->is($this->ana));
        $this->assertTrue($task->creator->is($this->admin));
        $this->assertTrue($task->member->is($member));
        $this->assertSame(sprintf('TASK-%05d', $task->id), $task->code());

        $assignment = TaskAssignment::sole();
        $this->assertTrue($assignment->user->is($this->ana));
        $this->assertTrue($assignment->assigner->is($this->admin));
        $this->assertNull($assignment->unassigned_at);

        $this->assertSame([TaskProgressUpdate::CREATED, TaskProgressUpdate::ASSIGNED], $task->updates->pluck('type')->all());
        $this->assertTrue($task->updates->every(fn ($u) => $u->user_id === $this->admin->id));

        Event::assertDispatched(TaskAssigned::class, fn ($e) => $e->task->is($task) && $e->assignee->is($this->ana) && $e->previousAssignee === null);
    }

    public static function invalidTasks(): array
    {
        return [
            'missing title' => [['title' => ''], 'title'],
            'long title' => [['title' => str_repeat('x', 151)], 'title'],
            'bad priority' => [['priority' => 'critical'], 'priority'],
            'past due date' => [['due_date' => '2026-11-11'], 'due_date'],
            'missing assignee' => [['assigned_to' => ''], 'assigned_to'],
            'unknown assignee' => [['assigned_to' => 999999], 'assigned_to'],
            'unknown member' => [['member_id' => 999999], 'member_id'],
        ];
    }

    #[DataProvider('invalidTasks')]
    public function test_task_validation(array $override, string $field): void
    {
        $this->actingAs($this->admin)->post(route('admin.tasks.store'), array_merge([
            'title' => 'Valid', 'priority' => 'medium', 'due_date' => '2026-11-30', 'assigned_to' => $this->ana->id,
        ], $override))->assertSessionHasErrors($field);

        $this->assertSame(0, Task::count());
    }

    public function test_tasks_cannot_be_assigned_to_admins_or_inactive_staff(): void
    {
        $inactive = User::factory()->member()->inactive()->create(['name' => 'Inactive Ivy']);
        $otherAdmin = User::factory()->admin()->create();

        foreach ([$inactive, $otherAdmin] as $user) {
            $this->actingAs($this->admin)->post(route('admin.tasks.store'), [
                'title' => 'X', 'priority' => 'low', 'assigned_to' => $user->id,
            ])->assertSessionHasErrors('assigned_to');
        }

        $this->actingAs($this->admin)->get(route('admin.tasks.create'))->assertDontSee('Inactive Ivy');
        $this->assertSame(0, Task::count());

        // Service-level guard (validation bypassed).
        $this->expectExceptionMessage("Inactive Ivy's account is inactive and cannot receive new tasks.");
        $this->task([], $inactive);
    }

    // ---- Edit --------------------------------------------------------------------

    public function test_admin_edits_details_and_the_change_is_logged(): void
    {
        $task = $this->task();

        $this->actingAs($this->admin)->get(route('admin.tasks.edit', $task))->assertOk();
        $this->actingAs($this->admin)->put(route('admin.tasks.update', $task), [
            'title' => 'Verify ALL member documents', 'description' => 'Check IDs for new applications.',
            'priority' => 'urgent', 'due_date' => '2026-11-15',
        ])->assertRedirect(route('admin.tasks.show', $task));

        $task->refresh();
        $this->assertSame('Verify ALL member documents', $task->title);
        $this->assertSame(TaskPriority::Urgent, $task->priority);
        $last = $task->updates->last();
        $this->assertSame(TaskProgressUpdate::UPDATED, $last->type);
        $this->assertStringContainsString('title', $last->note);
        $this->assertStringContainsString('priority', $last->note);

        // Saving without changes writes no extra activity.
        $count = $task->updates()->count();
        $this->service->updateDetails($task, ['title' => $task->title], $this->admin);
        $this->assertSame($count, $task->updates()->count());
    }

    public function test_editing_cannot_change_status_progress_or_assignee(): void
    {
        $task = $this->task();

        $this->actingAs($this->admin)->put(route('admin.tasks.update', $task), [
            'title' => 'T', 'priority' => 'low', 'status' => 'completed', 'progress' => 100, 'assigned_to' => $this->ben->id,
        ]);

        $task->refresh();
        $this->assertSame(TaskStatus::Todo, $task->status);
        $this->assertSame(0, $task->progress);
        $this->assertTrue($task->assignee->is($this->ana));
    }

    // ---- Reassign ------------------------------------------------------------------

    public function test_reassignment_preserves_assignment_history(): void
    {
        Event::fake([TaskAssigned::class]);
        $task = $this->task();
        $this->travel(2)->hours();

        $this->actingAs($this->admin)->patch(route('admin.tasks.reassign', $task), ['assigned_to' => $this->ben->id])
            ->assertRedirect(route('admin.tasks.show', $task));

        $task->refresh()->load('assignments.user');
        $this->assertTrue($task->assignee->is($this->ben));

        $assignments = $task->assignments;
        $this->assertCount(2, $assignments);
        $this->assertTrue($assignments[0]->user->is($this->ana));
        $this->assertSame('2026-11-12 11:00:00', $assignments[0]->unassigned_at->toDateTimeString());
        $this->assertTrue($assignments[1]->user->is($this->ben));
        $this->assertNull($assignments[1]->unassigned_at);

        $last = $task->updates->last();
        $this->assertSame(TaskProgressUpdate::REASSIGNED, $last->type);
        $this->assertSame('Reassigned from Ana Staff to Ben Staff.', $last->note);

        Event::assertDispatched(TaskAssigned::class, fn ($e) => $e->assignee->is($this->ben) && $e->previousAssignee?->is($this->ana));

        $this->actingAs($this->admin)->get(route('admin.tasks.show', $task))
            ->assertSee('Assignment history')->assertSee('Ana Staff')->assertSee('Ben Staff')->assertSee('Reassigned from Ana Staff to Ben Staff.');
    }

    public function test_reassignment_rules(): void
    {
        $task = $this->task();
        $inactive = User::factory()->member()->inactive()->create();

        $this->actingAs($this->admin)->patch(route('admin.tasks.reassign', $task), ['assigned_to' => $this->ana->id])
            ->assertSessionHasErrors(['assigned_to' => 'The task is already assigned to Ana Staff.']);
        $this->actingAs($this->admin)->patch(route('admin.tasks.reassign', $task), ['assigned_to' => $inactive->id])
            ->assertSessionHasErrors('assigned_to');
        $this->actingAs($this->admin)->patch(route('admin.tasks.reassign', $task), ['assigned_to' => $this->admin->id])
            ->assertSessionHasErrors('assigned_to');

        $this->progress($task, $this->ana, ['status' => 'completed']);
        $this->actingAs($this->admin)->patch(route('admin.tasks.reassign', $task), ['assigned_to' => $this->ben->id])
            ->assertSessionHasErrors(['assigned_to' => 'Completed tasks cannot be reassigned. Reopen the task first.']);

        $this->assertSame(1, $task->assignments()->count());
    }

    // ---- Reopen ------------------------------------------------------------------

    public function test_admin_reopens_a_completed_task(): void
    {
        $task = $this->task();
        $this->progress($task, $this->ana, ['status' => 'completed', 'note' => 'Done']);

        $this->actingAs($this->admin)->patch(route('admin.tasks.reopen', $task), ['progress' => 100, 'reason' => 'x'])
            ->assertSessionHasErrors('progress');
        $this->actingAs($this->admin)->patch(route('admin.tasks.reopen', $task), ['progress' => 80])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->admin)->patch(route('admin.tasks.reopen', $task), ['progress' => 80, 'reason' => 'Two IDs were missing'])
            ->assertSessionHas('success');

        $task->refresh();
        $this->assertSame(TaskStatus::InProgress, $task->status);
        $this->assertSame(80, $task->progress);
        $this->assertNull($task->completed_at);
        $last = $task->updates->last();
        $this->assertSame(TaskProgressUpdate::REOPENED, $last->type);
        $this->assertSame(TaskStatus::Completed, $last->old_status);
        $this->assertSame(100, $last->old_progress);
        $this->assertSame('Two IDs were missing', $last->note);

        // The assignee can work on it again.
        $this->assertNull($this->service->progressBlocker($task, $this->ana));
    }

    public function test_only_completed_tasks_can_be_reopened(): void
    {
        $task = $this->task();

        $this->actingAs($this->admin)->patch(route('admin.tasks.reopen', $task), ['progress' => 50, 'reason' => 'Why'])
            ->assertSessionHas('error', 'Only completed tasks can be reopened.');
    }

    // ---- Archive / trash ---------------------------------------------------------------

    public function test_archive_and_restore_from_archive(): void
    {
        $task = $this->task();

        $this->actingAs($this->admin)->patch(route('admin.tasks.archive', $task))->assertSessionHas('success');
        $task->refresh();
        $this->assertTrue($task->isArchived());
        $this->assertTrue($task->archiver->is($this->admin));

        $active = $this->actingAs($this->admin)->get(route('admin.tasks.index'))->viewData('tasks');
        $archived = $this->actingAs($this->admin)->get(route('admin.tasks.index', ['view' => 'archived']))->viewData('tasks');
        $this->assertFalse($active->contains($task));
        $this->assertTrue($archived->contains($task));

        // Archived tasks are frozen until restored.
        $this->actingAs($this->admin)->get(route('admin.tasks.edit', $task))->assertRedirect(route('admin.tasks.show', $task));
        $this->actingAs($this->admin)->patch(route('admin.tasks.reassign', $task), ['assigned_to' => $this->ben->id])->assertSessionHas('error');
        $this->assertNotNull($this->service->progressBlocker($task, $this->ana));

        $this->actingAs($this->admin)->patch(route('admin.tasks.unarchive', $task))->assertSessionHas('success');
        $this->assertFalse($task->fresh()->isArchived());
        $this->assertSame([TaskProgressUpdate::ARCHIVED, TaskProgressUpdate::UNARCHIVED], $task->updates()->pluck('type')->slice(-2)->values()->all());
    }

    public function test_soft_delete_keeps_history_and_can_be_restored(): void
    {
        $task = $this->task();
        $this->service->reassign($task, $this->ben, $this->admin);
        $this->progress($task, $this->ben, ['status' => 'in_progress', 'progress' => 30]);
        $assignments = TaskAssignment::count();
        $updates = TaskProgressUpdate::count();

        $this->actingAs($this->admin)->delete(route('admin.tasks.destroy', $task))
            ->assertRedirect(route('admin.tasks.index', ['view' => 'trash']));

        $this->assertSoftDeleted($task);
        $this->assertSame($assignments, TaskAssignment::count());
        $this->assertSame($updates + 1, TaskProgressUpdate::count()); // + "moved to trash"

        $trash = $this->actingAs($this->admin)->get(route('admin.tasks.index', ['view' => 'trash']))->viewData('tasks');
        $this->assertTrue($trash->contains('id', $task->id));
        $this->actingAs($this->admin)->get(route('admin.tasks.show', $task))->assertOk()->assertSee('This task is in the trash');

        // Staff (current and previous) cannot see a trashed task.
        $this->actingAs($this->ben)->get(route('my.tasks.show', $task))->assertNotFound();

        $this->actingAs($this->admin)->patch(route('admin.tasks.restore', $task))->assertSessionHas('success');
        $this->assertNotSoftDeleted($task);
        $this->assertSame(TaskProgressUpdate::RESTORED, $task->updates()->get()->last()->type);
        $this->assertSame(30, $task->fresh()->progress);
    }

    public function test_there_is_no_hard_delete(): void
    {
        $task = $this->task();
        $this->service->trash($task, $this->admin);

        $this->actingAs($this->admin)->delete(route('admin.tasks.destroy', $task))->assertNotFound(); // already trashed
        $this->assertSame(1, Task::withTrashed()->count());
    }

    // ---- Directory ---------------------------------------------------------------

    public function test_directory_search_filters_and_overdue_indicator(): void
    {
        $overdue = $this->task(['title' => 'Overdue filing', 'due_date' => '2026-11-12', 'priority' => 'urgent']);
        $this->travelTo(now()->setDate(2026, 11, 14));
        $benTask = $this->task(['title' => 'Print member cards', 'description' => 'Laminate them', 'due_date' => null], $this->ben);
        $done = $this->task(['title' => 'Finished thing', 'due_date' => '2026-11-15']);
        $this->progress($done, $this->ana, ['status' => 'completed']);

        $ids = fn (array $q) => $this->actingAs($this->admin)->get(route('admin.tasks.index', $q))->viewData('tasks')->pluck('id')->all();

        $this->assertSame([$benTask->id], $ids(['q' => 'laminate']));
        $this->assertSame([$overdue->id], $ids(['q' => 'Overdue filing']));
        $this->assertSame([$done->id], $ids(['status' => 'completed']));
        $this->assertSame([$overdue->id], $ids(['priority' => 'urgent']));
        $this->assertSame([$benTask->id], $ids(['assignee' => $this->ben->id]));
        $this->assertSame([$overdue->id], $ids(['due' => 'overdue']));
        $this->assertSame([$benTask->id], $ids(['due' => 'none']));

        $this->actingAs($this->admin)->get(route('admin.tasks.index'))->assertSee('Overdue');
        $this->actingAs($this->admin)->get(route('admin.tasks.index', ['q' => 'nothing']))->assertSee('No tasks match your filters');
    }

    public function test_directory_pagination(): void
    {
        foreach (range(1, 17) as $i) {
            $this->task(['title' => "Task {$i}"]);
        }

        $this->assertCount(2, $this->actingAs($this->admin)->get(route('admin.tasks.index', ['page' => 2]))->viewData('tasks'));
    }

    // ---- Deactivated staff ---------------------------------------------------------------

    public function test_history_is_preserved_when_a_staff_account_is_deactivated(): void
    {
        $task = $this->task();
        $this->progress($task, $this->ana, ['status' => 'in_progress', 'progress' => 50, 'note' => 'Halfway']);

        $this->ana->forceFill(['is_active' => false])->save();

        $this->actingAs($this->admin)->get(route('admin.tasks.show', $task))
            ->assertOk()->assertSee('Ana Staff')->assertSee('Inactive account')->assertSee('Halfway');
        $this->actingAs($this->admin)->get(route('admin.tasks.create'))->assertDontSee('Ana Staff');

        // The admin can hand the work to someone active; Ana's history stays.
        $this->service->reassign($task, $this->ben, $this->admin);
        $this->assertSame(2, $task->assignments()->count());
        $this->assertSame(1, TaskProgressUpdate::where('user_id', $this->ana->id)->count());
    }
}
