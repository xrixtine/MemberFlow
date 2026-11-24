<?php

namespace Tests\Feature\Tasks;

class TaskDashboardTest extends TaskTestCase
{
    public function test_admin_dashboard_task_statistics_and_recent_activity(): void
    {
        $this->task(['title' => 'Todo one']);
        $inProgress = $this->task(['title' => 'Working on it']);
        $this->progress($inProgress, $this->ana, ['status' => 'in_progress', 'progress' => 50]);
        $onHold = $this->task([], $this->ben);
        $this->progress($onHold, $this->ben, ['status' => 'on_hold', 'progress' => 10]);
        $done = $this->task();
        $this->progress($done, $this->ana, ['status' => 'completed']);
        $this->task(['due_date' => '2026-11-12', 'title' => 'Due today']);
        $archived = $this->task();
        $this->service->archive($archived, $this->admin);
        $trashed = $this->task();
        $this->service->trash($trashed, $this->admin);

        $this->travelTo(now()->addDays(2)); // "Due today" is now overdue

        $response = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $this->assertSame(
            ['total' => 5, 'todo' => 2, 'in_progress' => 1, 'on_hold' => 1, 'completed' => 1, 'overdue' => 1],
            $response->viewData('taskStats'),
        );

        // Newest first, limited to 6, real rows from task_progress_updates.
        $activity = $response->viewData('recentActivity');
        $this->assertCount(6, $activity);
        $this->assertSame('deleted', $activity->first()->type);
        $this->assertTrue($activity->first()->task->is($trashed));
        $this->assertTrue($activity->first()->user->is($this->admin));
        $response->assertSee('Recent task activity')->assertSee('Moved to trash');
    }

    public function test_member_dashboard_statistics_and_upcoming_deadlines(): void
    {
        $this->task(['title' => 'Due tomorrow', 'due_date' => '2026-11-13']);
        $started = $this->task(['title' => 'Started', 'due_date' => '2026-12-31']);
        $this->progress($started, $this->ana, ['status' => 'in_progress', 'progress' => 30]);
        $done = $this->task(['title' => 'Done']);
        $this->progress($done, $this->ana, ['status' => 'completed']);
        $late = $this->task(['title' => 'Late one', 'due_date' => '2026-11-12']);
        $this->task(['title' => 'Bens'], $this->ben);
        $this->travelTo(now()->addDay()->setTime(9, 0)); // 2026-11-13: "Late one" is overdue

        $response = $this->actingAs($this->ana)->get(route('dashboard'))->assertOk();
        $stats = $response->viewData('tasks');

        $this->assertSame(4, $stats['total']);
        $this->assertSame(2, $stats['todo']);
        $this->assertSame(1, $stats['in_progress']);
        $this->assertSame(1, $stats['completed']);
        $this->assertSame(1, $stats['overdue']);
        $this->assertSame(['Due tomorrow'], $stats['upcoming']->pluck('title')->all());
        $response->assertSee('Upcoming deadlines')->assertDontSee('Bens');
    }
}
