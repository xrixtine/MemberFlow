<?php

namespace App\Events;

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a task is assigned or reassigned (only once the DB transaction
 * commits). Phase 7 attaches the "task assigned" notification as a listener;
 * no email is sent in Phase 6.
 */
class TaskAssigned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Task $task,
        public readonly User $assignee,
        public readonly User $assignedBy,
        public readonly ?User $previousAssignee = null,
    ) {}
}
