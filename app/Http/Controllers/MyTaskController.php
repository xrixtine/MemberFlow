<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskProgressUpdate;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "My Tasks" for members: current assignments (editable) and tasks previously
 * handled (read-only), plus the user's own task history.
 */
class MyTaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $tab = $request->query('tab') === 'previous' ? 'previous' : 'current';
        $status = TaskStatus::tryFrom((string) $request->query('status'));

        $query = $tab === 'current'
            ? Task::query()->where('assigned_to', $user->id)
            : Task::query()
                ->where(fn ($q) => $q->whereNull('assigned_to')->orWhere('assigned_to', '!=', $user->id))
                ->whereHas('assignments', fn ($a) => $a->where('user_id', $user->id));

        $tasks = $query
            ->with(['assignee', 'creator'])
            ->when($status, fn ($q, $s) => $q->where('status', $s->value))
            ->orderByRaw("CASE WHEN status = 'completed' THEN 1 ELSE 0 END")
            ->orderByRaw('due_date IS NULL, due_date')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        return view('my-tasks.index', [
            // Members whose membership is not active/current see their tasks read-only (R5).
            'readOnly' => $user->isMember() && $this->tasks->assignmentBlocker($user) !== null,
            'tasks' => $tasks,
            'tab' => $tab,
            'status' => $status,
            'counts' => [
                'current' => Task::where('assigned_to', $user->id)->count(),
                'previous' => Task::where(fn ($q) => $q->whereNull('assigned_to')->orWhere('assigned_to', '!=', $user->id))
                    ->whereHas('assignments', fn ($a) => $a->where('user_id', $user->id))->count(),
            ],
        ]);
    }

    public function show(Request $request, Task $task): View
    {
        $this->authorize('view', $task);

        $task->load([
            'assignee', 'creator', 'member:id,first_name,middle_name,last_name',
            'assignments' => fn ($q) => $q->with(['user', 'assigner']),
            'updates' => fn ($q) => $q->with('user'),
        ]);

        return view('my-tasks.show', [
            'task' => $task,
            'blocker' => $this->tasks->progressBlocker($task, $request->user()),
            'isCurrent' => $task->isCurrentlyAssignedTo($request->user()),
        ]);
    }

    /** Completed tasks the user handled, and every update they have recorded. */
    public function history(Request $request): View
    {
        $user = $request->user();

        $completed = Task::query()
            ->where('status', TaskStatus::Completed->value)
            ->whereHas('assignments', fn ($a) => $a->where('user_id', $user->id))
            ->with('assignee')
            ->orderByDesc('completed_at')
            ->get();

        $updates = TaskProgressUpdate::query()
            ->where('user_id', $user->id)
            ->whereHas('task', fn ($q) => $q->whereNull('tasks.deleted_at'))
            ->with('task')
            ->latest()
            ->latest('id')
            ->paginate(20);

        return view('my-tasks.history', compact('completed', 'updates'));
    }
}
