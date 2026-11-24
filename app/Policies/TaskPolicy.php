<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;

/**
 * Task authorization.
 *  - Administrators manage everything (create, assign, edit, archive, delete, reopen).
 *  - Members (and staff, during the transition) may VIEW tasks they are or were
 *    assigned to (previous assignees: read-only), and UPDATE PROGRESS only on their
 *    current, non-archived, non-completed tasks. They can never create or assign tasks.
 */
class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Task $task): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $user->is_active
            && $user->worksOnTasks()
            && ! $task->trashed()
            && ($task->isCurrentlyAssignedTo($user) || $task->wasAssignedTo($user));
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->isAdmin($user);
    }

    public function assign(User $user, Task $task): bool
    {
        return $this->isAdmin($user);
    }

    public function reopen(User $user, Task $task): bool
    {
        return $this->isAdmin($user);
    }

    public function archive(User $user, Task $task): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->isAdmin($user);
    }

    public function restore(User $user, Task $task): bool
    {
        return $this->isAdmin($user);
    }

    public function updateProgress(User $user, Task $task): bool
    {
        return app(TaskService::class)->progressBlocker($task, $user) === null;
    }

    private function isAdmin(User $user): bool
    {
        return $user->isAdmin() && $user->is_active;
    }
}
