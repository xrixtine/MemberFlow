<?php

namespace App\Services;

use App\Enums\MemberStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Events\TaskAssigned;
use App\Events\TaskProgressRecorded;
use App\Exceptions\TaskException;
use App\Models\Member;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskProgressUpdate as Activity;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Task business rules. Every change:
 *  - runs in a DB transaction with the task row locked (SELECT ... FOR UPDATE),
 *  - writes an activity entry (who, what, when) to task_progress_updates,
 *  - keeps assignment periods in task_assignments (never deleted).
 *
 * Progress/status consistency: progress 100 ⇔ status Completed.
 *
 * Notification hooks: TaskAssigned and TaskProgressRecorded events are
 * dispatched after commit; Phase 7 attaches notification listeners.
 */
class TaskService
{
    public const DETAIL_FIELDS = ['title', 'description', 'priority', 'due_date', 'member_id'];

    // =====================================================================
    // Administrator actions
    // =====================================================================

    public function create(array $data, User $assignee, User $admin): Task
    {
        $task = DB::transaction(function () use ($data, &$assignee, $admin) {
            $assignee = $this->lockAssignee($assignee);
            $this->assertAssignable($assignee);

            $task = new Task(Arr::only($data, self::DETAIL_FIELDS));
            $task->forceFill([
                'priority' => $data['priority'] ?? TaskPriority::Medium,
                'status' => TaskStatus::Todo,
                'progress' => 0,
                'created_by' => $admin->id,
            ])->save();

            $this->log($task, $admin, Activity::CREATED, newStatus: TaskStatus::Todo, newProgress: 0);
            $this->openAssignment($task, $assignee, $admin);
            $this->log($task, $admin, Activity::ASSIGNED, note: "Assigned to {$assignee->name}.");

            return $task;
        });

        TaskAssigned::dispatch($task, $assignee, $admin, null);

        return $task;
    }

    /** Edit title/description/priority/due date/related member (not status, progress or assignee). */
    public function updateDetails(Task $task, array $data, User $admin): Task
    {
        return DB::transaction(function () use ($task, $data, $admin) {
            $task = $this->lock($task);
            $this->assertEditable($task);

            $task->fill(Arr::only($data, self::DETAIL_FIELDS));
            $changed = array_keys($task->getDirty());

            if ($changed) {
                $task->save();
                $labels = array_map(fn ($f) => str_replace(['_id', '_'], ['', ' '], $f), $changed);
                $this->log($task, $admin, Activity::UPDATED, note: 'Changed '.implode(', ', $labels).'.');
            }

            return $task;
        });
    }

    /**
     * Reassign to another eligible member account. The
     * previous assignment period is closed (kept for history) and a new one is opened.
     */
    public function reassign(Task $task, User $assignee, User $admin): Task
    {
        $previous = null;

        $task = DB::transaction(function () use ($task, &$assignee, $admin, &$previous) {
            $task = $this->lock($task);
            $this->assertEditable($task);
            $assignee = $this->lockAssignee($assignee);
            $this->assertAssignable($assignee);

            if ($task->isCompleted()) {
                throw new TaskException('Completed tasks cannot be reassigned. Reopen the task first.', 'assigned_to');
            }
            if ($task->isCurrentlyAssignedTo($assignee)) {
                throw new TaskException("The task is already assigned to {$assignee->name}.", 'assigned_to');
            }

            $previous = $task->assigned_to ? User::find($task->assigned_to) : null;
            $this->openAssignment($task, $assignee, $admin);
            $this->log($task, $admin, $previous ? Activity::REASSIGNED : Activity::ASSIGNED,
                note: $previous ? "Reassigned from {$previous->name} to {$assignee->name}." : "Assigned to {$assignee->name}.");

            return $task;
        });

        TaskAssigned::dispatch($task, $assignee, $admin, $previous);

        return $task;
    }

    /** Administrator reopens a completed task with a reason and a new (<100) progress value. */
    public function reopen(Task $task, User $admin, int $progress, string $reason): Task
    {
        return DB::transaction(function () use ($task, $admin, $progress, $reason) {
            $task = $this->lock($task);
            $this->assertEditable($task);

            if (! $task->isCompleted()) {
                throw new TaskException('Only completed tasks can be reopened.');
            }
            if ($progress < 0 || $progress > 99) {
                throw new TaskException('A reopened task must have progress between 0 and 99%.', 'progress');
            }

            $this->log($task, $admin, Activity::REOPENED,
                oldStatus: $task->status, newStatus: TaskStatus::InProgress,
                oldProgress: $task->progress, newProgress: $progress, note: $reason);

            $task->forceFill([
                'status' => TaskStatus::InProgress,
                'progress' => $progress,
                'completed_at' => null,
            ])->save();

            return $task;
        });
    }

    public function archive(Task $task, User $admin): Task
    {
        return DB::transaction(function () use ($task, $admin) {
            $task = $this->lock($task);
            if ($task->isArchived()) {
                throw new TaskException('This task is already archived.');
            }

            $task->forceFill(['archived_at' => now(), 'archived_by' => $admin->id])->save();
            $this->log($task, $admin, Activity::ARCHIVED);

            return $task;
        });
    }

    public function unarchive(Task $task, User $admin): Task
    {
        return DB::transaction(function () use ($task, $admin) {
            $task = $this->lock($task);
            if (! $task->isArchived()) {
                throw new TaskException('This task is not archived.');
            }

            $task->forceFill(['archived_at' => null, 'archived_by' => null])->save();
            $this->log($task, $admin, Activity::UNARCHIVED);

            return $task;
        });
    }

    /** Soft delete (moves to trash). The row, its assignments and its activity are kept. */
    public function trash(Task $task, User $admin): Task
    {
        return DB::transaction(function () use ($task, $admin) {
            $task = $this->lock($task);
            $this->log($task, $admin, Activity::DELETED);
            $task->delete();

            return $task;
        });
    }

    public function restore(Task $task, User $admin): Task
    {
        return DB::transaction(function () use ($task, $admin) {
            $task = Task::withTrashed()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            if (! $task->trashed()) {
                throw new TaskException('This task is not in the trash.');
            }

            $task->restore();
            $this->log($task, $admin, Activity::RESTORED);

            return $task;
        });
    }

    // =====================================================================
    // Staff action
    // =====================================================================

    /** Why $user cannot record progress on $task now, or null when they can. */
    public function progressBlocker(Task $task, User $user): ?string
    {
        if (! $user->is_active || ! $user->worksOnTasks()) {
            return 'Only active member accounts can update task progress.';
        }
        if ($task->trashed()) {
            return 'This task has been deleted.';
        }
        if (! $task->isCurrentlyAssignedTo($user)) {
            return 'This task is no longer assigned to you. You can view its history but not change it.';
        }
        // R5: only members with an ACTIVE, current membership work on tasks. Pending or
        // expired members keep read-only access; an administrator can reassign the task.
        if ($user->isMember() && $this->assignmentBlocker($user) !== null) {
            return 'Your membership is not currently active, so your tasks are read-only. Please contact the membership office.';
        }
        if ($task->isArchived()) {
            return 'This task has been archived and can no longer be updated.';
        }
        if ($task->isCompleted()) {
            return 'This task is completed. Only an administrator can reopen it.';
        }

        return null;
    }

    /**
     * Record a progress update by the current assignee.
     *
     * @param  array{status?: TaskStatus|string|null, progress?: int|string|null, note?: string|null}  $input
     */
    public function recordProgress(Task $task, User $user, array $input): Activity
    {
        $activity = DB::transaction(function () use ($task, $user, $input) {
            $task = $this->lock($task);

            if ($blocker = $this->progressBlocker($task, $user)) {
                throw new TaskException($blocker);
            }

            $oldStatus = $task->status;
            $oldProgress = $task->progress;

            $status = isset($input['status']) && $input['status'] !== ''
                ? ($input['status'] instanceof TaskStatus ? $input['status'] : TaskStatus::from($input['status']))
                : $oldStatus;
            $progress = isset($input['progress']) && $input['progress'] !== '' ? (int) $input['progress'] : $oldProgress;
            $note = isset($input['note']) ? trim((string) $input['note']) : '';

            if ($progress < 0 || $progress > 100) {
                throw new TaskException('Progress must be between 0 and 100%.', 'progress');
            }

            // Consistency: 100% ⇔ Completed.
            if ($status === TaskStatus::Completed) {
                $progress = 100;
            } elseif ($progress === 100) {
                if ($status !== $oldStatus) {
                    throw new TaskException('A task at 100% is completed. Choose "Completed" or lower the progress.', 'progress');
                }
                $status = TaskStatus::Completed;
            }

            if ($status === $oldStatus && $progress === $oldProgress && $note === '') {
                throw new TaskException('Change the status or progress, or add a note.', 'note');
            }

            $type = match (true) {
                $status === TaskStatus::Completed => Activity::COMPLETED,
                $status !== $oldStatus => Activity::STATUS,
                $progress !== $oldProgress => Activity::PROGRESS,
                default => Activity::NOTE,
            };

            $task->forceFill([
                'status' => $status,
                'progress' => $progress,
                'completed_at' => $status === TaskStatus::Completed ? now() : null,
            ])->save();

            return $this->log($task, $user, $type,
                oldStatus: $oldStatus, newStatus: $status,
                oldProgress: $oldProgress, newProgress: $progress,
                note: $note === '' ? null : $note);
        });

        TaskProgressRecorded::dispatch($activity->task()->first(), $activity);

        return $activity;
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Why $user cannot receive a NEW task assignment, or null when they can.
     * Same rule as User::scopeEligibleForTasks(). Existing assignments are never
     * changed by this rule; it only gates new assignments and reassignments.
     */
    public function assignmentBlocker(User $user): ?string
    {
        if (! $user->is_active) {
            return "{$user->name}'s account is inactive and cannot receive new tasks.";
        }
        if (! $user->isMember()) {
            return 'Tasks can only be assigned to member accounts.';
        }

        $member = $user->member_id ? Member::withTrashed()->find($user->member_id) : null;

        return match (true) {
            $member === null || $member->trashed() => "{$user->name}'s account is not linked to a current membership record.",
            $member->status !== MemberStatus::Active => "{$user->name}'s membership is ".strtolower($member->status->label()).'; only active members can receive tasks.',
            $member->membership_end === null || $member->membership_end->lt(today()) => "{$user->name}'s membership has ended; only members with a current membership can receive tasks.",
            default => null,
        };
    }

    public function assertAssignable(User $user): void
    {
        if ($blocker = $this->assignmentBlocker($user)) {
            throw new TaskException($blocker, 'assigned_to');
        }
    }

    /**
     * Re-read the assignee and their member record under row locks inside the
     * current transaction, so a deactivation/expiry committed meanwhile cannot slip through.
     */
    private function lockAssignee(User $assignee): User
    {
        $locked = User::whereKey($assignee->id)->lockForUpdate()->firstOrFail();
        if ($locked->member_id) {
            Member::withTrashed()->whereKey($locked->member_id)->lockForUpdate()->first();
        }

        return $locked;
    }

    private function assertEditable(Task $task): void
    {
        if ($task->trashed()) {
            throw new TaskException('This task is in the trash. Restore it first.');
        }
        if ($task->isArchived()) {
            throw new TaskException('This task is archived. Restore it from the archive first.');
        }
    }

    private function lock(Task $task): Task
    {
        return Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
    }

    /** Close the open assignment period (if any) and open a new one. */
    private function openAssignment(Task $task, User $assignee, User $admin): void
    {
        $now = now();

        TaskAssignment::where('task_id', $task->id)->whereNull('unassigned_at')->update(['unassigned_at' => $now]);

        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $assignee->id,
            'assigned_by' => $admin->id,
            'assigned_at' => $now,
        ]);

        $task->forceFill(['assigned_to' => $assignee->id])->save();
    }

    private function log(
        Task $task,
        User $user,
        string $type,
        ?TaskStatus $oldStatus = null,
        ?TaskStatus $newStatus = null,
        ?int $oldProgress = null,
        ?int $newProgress = null,
        ?string $note = null,
    ): Activity {
        return Activity::create([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'type' => $type,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'old_progress' => $oldProgress,
            'new_progress' => $newProgress,
            'note' => $note,
        ]);
    }
}
