<?php

namespace Tests\Feature\Tasks;

use App\Enums\TaskStatus;
use App\Events\TaskProgressRecorded;
use App\Models\Task;
use App\Models\TaskProgressUpdate;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;

class StaffTaskTest extends TaskTestCase
{
    public function test_staff_sees_only_their_current_tasks(): void
    {
        $mine = $this->task(['title' => 'Ana task']);
        $this->task(['title' => 'Ben task'], $this->ben);

        $this->actingAs($this->ana)->get(route('my.tasks.index'))
            ->assertOk()->assertSee('Ana task')->assertDontSee('Ben task');

        $this->actingAs($this->ana)->get(route('my.tasks.show', $mine))
            ->assertOk()->assertSee('Update progress')->assertSee('name="progress"', false);
    }

    public function test_staff_cannot_open_unrelated_tasks(): void
    {
        $bens = $this->task([], $this->ben);

        $this->actingAs($this->ana)->get(route('my.tasks.show', $bens))->assertForbidden();
        $this->actingAs($this->ana)->post(route('my.tasks.progress', $bens), ['status' => 'in_progress', 'progress' => 10])->assertForbidden();
        $this->assertSame(TaskStatus::Todo, $bens->fresh()->status);
    }

    public function test_previous_assignee_has_read_only_access(): void
    {
        $task = $this->task(['title' => 'Handover task']);
        $this->progress($task, $this->ana, ['status' => 'in_progress', 'progress' => 20, 'note' => 'Started']);
        $this->service->reassign($task, $this->ben, $this->admin);

        // Listed under "previously handled", not current.
        $this->actingAs($this->ana)->get(route('my.tasks.index'))->assertDontSee('Handover task');
        $this->actingAs($this->ana)->get(route('my.tasks.index', ['tab' => 'previous']))->assertSee('Handover task')->assertSee('Read-only');

        $this->actingAs($this->ana)->get(route('my.tasks.show', $task))
            ->assertOk()
            ->assertSee('Read-only.')
            ->assertSee('Started')
            ->assertDontSee('name="progress"', false);

        $this->actingAs($this->ana)->post(route('my.tasks.progress', $task), ['status' => 'completed', 'progress' => 100])->assertForbidden();
        $this->assertSame(20, $task->fresh()->progress);

        // The new assignee can update it.
        $this->actingAs($this->ben)->post(route('my.tasks.progress', $task), ['status' => 'in_progress', 'progress' => 60])
            ->assertRedirect(route('my.tasks.show', $task));
        $this->assertSame(60, $task->fresh()->progress);
    }

    public function test_status_progress_and_note_updates_are_logged(): void
    {
        Event::fake([TaskProgressRecorded::class]);
        $task = $this->task();

        $this->actingAs($this->ana)->post(route('my.tasks.progress', $task), [
            'status' => 'in_progress', 'progress' => 40, 'note' => 'Checked 4 of 10 files',
        ])->assertSessionHas('success', 'Progress update saved.');

        $task->refresh()->load('updates.user');
        $this->assertSame(TaskStatus::InProgress, $task->status);
        $this->assertSame(40, $task->progress);

        $entry = $task->updates->last();
        $this->assertSame(TaskProgressUpdate::STATUS, $entry->type);
        $this->assertTrue($entry->user->is($this->ana));
        $this->assertSame(TaskStatus::Todo, $entry->old_status);
        $this->assertSame(TaskStatus::InProgress, $entry->new_status);
        $this->assertSame([0, 40], [$entry->old_progress, $entry->new_progress]);
        $this->assertSame('Checked 4 of 10 files', $entry->note);

        // Progress only, then note only, then status to on hold.
        $this->progress($task, $this->ana, ['status' => 'in_progress', 'progress' => 70]);
        $this->progress($task, $this->ana, ['status' => 'in_progress', 'progress' => 70, 'note' => 'Waiting for a signature']);
        $this->progress($task, $this->ana, ['status' => 'on_hold', 'progress' => 70]);
        $this->assertSame(
            [TaskProgressUpdate::PROGRESS, TaskProgressUpdate::NOTE, TaskProgressUpdate::STATUS],
            $task->updates()->pluck('type')->slice(-3)->values()->all(),
        );

        Event::assertDispatchedTimes(TaskProgressRecorded::class, 4);
    }

    public function test_an_update_without_any_change_is_rejected(): void
    {
        $task = $this->task();

        $this->actingAs($this->ana)->post(route('my.tasks.progress', $task), ['status' => 'todo', 'progress' => 0, 'note' => '  '])
            ->assertSessionHasErrors(['note' => 'Change the status or progress, or add a note.']);
    }

    public static function invalidProgress(): array
    {
        return ['above 100' => [101], 'negative' => [-5], 'text' => ['half'], 'missing' => ['']];
    }

    #[DataProvider('invalidProgress')]
    public function test_progress_must_be_between_0_and_100(mixed $progress): void
    {
        $task = $this->task();

        $this->actingAs($this->ana)->post(route('my.tasks.progress', $task), ['status' => 'in_progress', 'progress' => $progress])
            ->assertSessionHasErrors('progress');

        $this->assertSame(0, $task->fresh()->progress);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $task = $this->task();

        $this->actingAs($this->ana)->post(route('my.tasks.progress', $task), ['status' => 'done', 'progress' => 50])
            ->assertSessionHasErrors('status');
    }

    public function test_reaching_100_percent_completes_the_task(): void
    {
        $task = $this->task();
        $this->progress($task, $this->ana, ['status' => 'in_progress', 'progress' => 50]);
        $this->travel(1)->hour();

        // Same status + 100% ⇒ completed automatically.
        $this->actingAs($this->ana)->post(route('my.tasks.progress', $task), ['status' => 'in_progress', 'progress' => 100])
            ->assertSessionHas('success', 'Task marked as completed. Nice work!');

        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertSame(100, $task->progress);
        $this->assertSame('2026-11-12 10:00:00', $task->completed_at->toDateTimeString());
        $this->assertSame(TaskProgressUpdate::COMPLETED, $task->updates->last()->type);
    }

    public function test_choosing_completed_sets_progress_to_100(): void
    {
        $task = $this->task();

        $this->progress($task, $this->ana, ['status' => 'completed', 'progress' => 30]);

        $this->assertSame(100, $task->fresh()->progress);
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }

    public function test_100_percent_with_an_explicit_non_completed_status_change_is_rejected(): void
    {
        $task = $this->task();

        $this->actingAs($this->ana)->post(route('my.tasks.progress', $task), ['status' => 'on_hold', 'progress' => 100])
            ->assertSessionHasErrors('progress');

        $this->assertSame(TaskStatus::Todo, $task->fresh()->status);
    }

    public function test_completed_and_archived_tasks_cannot_be_modified_by_staff(): void
    {
        $completed = $this->task();
        $this->progress($completed, $this->ana, ['status' => 'completed']);

        $archived = $this->task();
        $this->service->archive($archived, $this->admin);

        foreach ([$completed, $archived] as $task) {
            $this->actingAs($this->ana)->get(route('my.tasks.show', $task))->assertOk()->assertDontSee('name="progress"', false);
            $this->actingAs($this->ana)->post(route('my.tasks.progress', $task), ['status' => 'in_progress', 'progress' => 10])->assertForbidden();
        }

        $this->assertSame(TaskStatus::Completed, $completed->fresh()->status);
        $this->assertSame(TaskStatus::Todo, $archived->fresh()->status);
    }

    public function test_staff_history_page(): void
    {
        $done = $this->task(['title' => 'Completed by Ana']);
        $this->progress($done, $this->ana, ['status' => 'completed', 'note' => 'All verified']);
        $other = $this->task(['title' => 'Other work']);
        $this->progress($other, $this->ana, ['status' => 'in_progress', 'progress' => 25, 'note' => 'Quarter done']);

        $this->actingAs($this->ana)->get(route('my.history'))
            ->assertOk()
            ->assertSee('Completed by Ana')
            ->assertSee('All verified')
            ->assertSee('Quarter done')
            ->assertSee('0% → 25%');
    }

    public function test_staff_cannot_use_any_admin_task_route(): void
    {
        $task = $this->task();

        $requests = [
            ['get', route('admin.tasks.index'), []],
            ['get', route('admin.tasks.create'), []],
            ['post', route('admin.tasks.store'), ['title' => 'Hack', 'priority' => 'low', 'assigned_to' => $this->ana->id]],
            ['get', route('admin.tasks.show', $task), []],
            ['get', route('admin.tasks.edit', $task), []],
            ['put', route('admin.tasks.update', $task), ['title' => 'Hack', 'priority' => 'low']],
            ['patch', route('admin.tasks.reassign', $task), ['assigned_to' => $this->ana->id]],
            ['patch', route('admin.tasks.reopen', $task), ['progress' => 1, 'reason' => 'x']],
            ['patch', route('admin.tasks.archive', $task), []],
            ['patch', route('admin.tasks.unarchive', $task), []],
            ['delete', route('admin.tasks.destroy', $task), []],
            ['patch', route('admin.tasks.restore', $task), []],
        ];

        foreach ($requests as [$method, $url, $data]) {
            $this->actingAs($this->ana)->{$method}($url, $data)->assertForbidden();
        }

        auth()->logout();
        foreach ($requests as [$method, $url, $data]) {
            $this->{$method}($url, $data)->assertRedirect(route('login'));
        }
        $this->get(route('my.tasks.index'))->assertRedirect(route('login'));

        $this->assertSame('Verify member documents', $task->fresh()->title);
        $this->assertSame(1, Task::count());
    }

    public function test_admins_do_not_use_the_staff_progress_route(): void
    {
        $task = $this->task();

        $this->actingAs($this->admin)->post(route('my.tasks.progress', $task), ['status' => 'completed', 'progress' => 100])->assertForbidden();
        $this->assertSame(TaskStatus::Todo, $task->fresh()->status);
    }

    public function test_deactivated_staff_are_signed_out_and_cannot_update(): void
    {
        $task = $this->task();
        $this->actingAs($this->ana);
        $this->ana->forceFill(['is_active' => false])->save();

        $this->post(route('my.tasks.progress', $task), ['status' => 'in_progress', 'progress' => 10])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(0, $task->fresh()->progress);

        $this->assertSame('Only active member accounts can update task progress.', $this->service->progressBlocker($task, $this->ana->fresh()));
    }

    public function test_staff_task_list_filter_by_status(): void
    {
        $this->task(['title' => 'Still todo']);
        $started = $this->task(['title' => 'Already started']);
        $this->progress($started, $this->ana, ['status' => 'in_progress', 'progress' => 10]);

        $this->actingAs($this->ana)->get(route('my.tasks.index', ['status' => 'in_progress']))
            ->assertSee('Already started')->assertDontSee('Still todo');
    }

    public function test_policy_matrix(): void
    {
        $task = $this->task();
        $this->service->reassign($task, $this->ben, $this->admin);
        $task->refresh(); // policies run against the current row, as route model binding provides
        $outsider = User::factory()->member()->create();

        $this->assertTrue($this->admin->can('view', $task));
        $this->assertTrue($this->ana->can('view', $task));          // previous assignee
        $this->assertFalse($this->ana->can('updateProgress', $task));
        $this->assertTrue($this->ben->can('updateProgress', $task)); // current assignee
        $this->assertFalse($outsider->can('view', $task));
        foreach (['update', 'assign', 'reopen', 'archive', 'delete', 'restore'] as $ability) {
            $this->assertTrue($this->admin->can($ability, $task), $ability);
            $this->assertFalse($this->ben->can($ability, $task), $ability);
        }
    }
}
