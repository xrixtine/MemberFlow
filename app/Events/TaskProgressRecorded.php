<?php

namespace App\Events;

use App\Models\Task;
use App\Models\TaskProgressUpdate;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after the assignee (a member) records progress (only once the DB transaction
 * commits). $update->type tells listeners what happened ("completed",
 * "status", "progress", "note"). Phase 7 notifies administrators about
 * important updates (completion, on hold) from a listener.
 */
class TaskProgressRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Task $task,
        public readonly TaskProgressUpdate $update,
    ) {}
}
