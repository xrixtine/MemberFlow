<?php

namespace App\Listeners;

use App\Enums\TaskStatus;
use App\Events\TaskAssigned;
use App\Events\TaskProgressRecorded;
use App\Models\TaskAssignment;
use App\Models\TaskProgressUpdate;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskReassignedAway;
use App\Notifications\TaskStatusNotification;
use App\Services\EmailDeliveryTracker;
use Illuminate\Support\Collection;

/**
 * Task emails, limited to meaningful events (no spam):
 *  - assignment / reassignment → the new assignee (a member account that can still sign in);
 *  - reassignment             → the previous assignee, so they stop working on it;
 * Emails carry only the task (title, code, priority, due date, link). They never include
 * the related member's details or anyone's payment information.
 *  - completed / put On Hold  → the administrator who created the task (or, if that
 *                               account is inactive, every active administrator).
 * Routine progress percentages and notes are visible in the app but not emailed.
 */
class SendTaskNotifications
{
    public function __construct(private readonly EmailDeliveryTracker $tracker) {}

    public function handleAssigned(TaskAssigned $event): void
    {
        $task = $event->task;
        $assignee = $event->assignee->fresh();

        $assignmentId = TaskAssignment::where('task_id', $task->id)
            ->where('user_id', $assignee?->id)
            ->whereNull('unassigned_at')
            ->latest('id')
            ->value('id');

        if ($assignee && $assignee->worksOnTasks() && $assignee->canSignIn() && $assignmentId) {
            $this->tracker->send(
                $assignee,
                new TaskAssignedNotification($task, $event->previousAssignee !== null, $event->assignedBy->name),
                $task,
                "task-assigned:assignment:{$assignmentId}",
            );
        }

        $previous = $event->previousAssignee?->fresh();
        if ($previous && $previous->canSignIn() && $previous->id !== $assignee?->id && $assignmentId) {
            $this->tracker->send(
                $previous,
                new TaskReassignedAway($task, $assignee?->name),
                $task,
                "task-reassigned-away:assignment:{$assignmentId}:user:{$previous->id}",
            );
        }
    }

    public function handleProgress(TaskProgressRecorded $event): void
    {
        $update = $event->update;

        $important = $update->type === TaskProgressUpdate::COMPLETED
            || ($update->type === TaskProgressUpdate::STATUS && $update->new_status === TaskStatus::OnHold);

        if (! $important) {
            return;
        }

        foreach ($this->adminRecipients($event->task->created_by) as $admin) {
            $this->tracker->send($admin, new TaskStatusNotification($update), $event->task, "task-status:update:{$update->id}:user:{$admin->id}");
        }
    }

    /** @return Collection<int, User> */
    private function adminRecipients(?int $creatorId): Collection
    {
        $creator = $creatorId ? User::active()->admins()->find($creatorId) : null;

        return $creator ? collect([$creator]) : User::active()->admins()->orderBy('id')->get();
    }
}
