<?php

namespace App\Http\Controllers;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\TaskException;
use App\Http\Requests\ReassignTaskRequest;
use App\Http\Requests\ReopenTaskRequest;
use App\Http\Requests\SaveTaskRequest;
use App\Models\Member;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Administrator task management: directory (active / archived / trash),
 * create, edit, assign/reassign, reopen, archive, soft delete and restore.
 */
class TaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Task::class);

        $view = in_array($request->query('view'), ['archived', 'trash'], true) ? $request->query('view') : 'active';
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => TaskStatus::tryFrom((string) $request->query('status')),
            'priority' => TaskPriority::tryFrom((string) $request->query('priority')),
            'assignee' => (int) $request->query('assignee') ?: null,
            'due' => in_array($request->query('due'), ['overdue', 'today', 'week', 'none'], true) ? $request->query('due') : null,
        ];

        $tasks = Task::query()
            ->with(['assignee', 'member'])
            ->when($view === 'active', fn ($q) => $q->active())
            ->when($view === 'archived', fn ($q) => $q->archived())
            ->when($view === 'trash', fn ($q) => $q->onlyTrashed())
            ->when($filters['q'] !== '', function ($q) use ($filters) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%';
                $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('description', 'like', $like));
            })
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s->value))
            ->when($filters['priority'], fn ($q, $p) => $q->where('priority', $p->value))
            ->when($filters['assignee'], fn ($q, $id) => $q->where('assigned_to', $id))
            ->when($filters['due'] === 'overdue', fn ($q) => $q->overdue())
            ->when($filters['due'] === 'today', fn ($q) => $q->whereDate('due_date', today()))
            ->when($filters['due'] === 'week', fn ($q) => $q->whereBetween('due_date', [today(), today()->addDays(7)]))
            ->when($filters['due'] === 'none', fn ($q) => $q->whereNull('due_date'))
            ->orderByRaw("CASE WHEN status = 'completed' THEN 1 ELSE 0 END")
            ->orderByRaw('due_date IS NULL, due_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('tasks.index', [
            'tasks' => $tasks,
            'view' => $view,
            'filters' => $filters,
            'hasFilters' => collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty(),
            // Everyone who holds or has held a task (members, and retired staff through their history).
            'assigneeFilter' => User::whereHas('taskAssignments')->orderBy('name')->get(['id', 'name', 'is_active']),
            'counts' => [
                'active' => Task::active()->count(),
                'archived' => Task::archived()->count(),
                'trash' => Task::onlyTrashed()->count(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Task::class);

        return view('tasks.create', [
            'task' => new Task(['priority' => TaskPriority::Medium]),
            'assignees' => $this->assigneeOptions(),
            'members' => $this->memberOptions(),
            'preselectedMember' => (int) $request->query('member') ?: null,
        ]);
    }

    public function store(SaveTaskRequest $request): RedirectResponse
    {
        try {
            $task = $this->tasks->create($request->validated(), User::findOrFail($request->validated('assigned_to')), $request->user());
        } catch (TaskException $e) {
            return back()->withInput()->withErrors([$e->field => $e->getMessage()]);
        }

        return redirect()->route('admin.tasks.show', $task)
            ->with('success', "{$task->code()} created and assigned to {$task->assignee->name}.");
    }

    public function show(Task $task): View
    {
        $this->authorize('view', $task);

        $task->load([
            'assignee.member', 'creator', 'archiver', 'member',
            'assignments' => fn ($q) => $q->with(['user', 'assigner']),
            'updates' => fn ($q) => $q->with('user'),
        ]);

        return view('tasks.show', [
            'task' => $task,
            'assignees' => $this->assigneeOptions(except: $task->assigned_to),
            // Unfinished task held by someone who could no longer receive it → prompt to reassign.
            'assigneeBlocker' => $task->assignee && ! $task->isCompleted() ? $this->tasks->assignmentBlocker($task->assignee) : null,
        ]);
    }

    public function edit(Task $task): View|RedirectResponse
    {
        $this->authorize('update', $task);

        if ($task->isArchived()) {
            return redirect()->route('admin.tasks.show', $task)->with('error', 'Restore the task from the archive before editing it.');
        }

        return view('tasks.edit', ['task' => $task, 'members' => $this->memberOptions()]);
    }

    public function update(SaveTaskRequest $request, Task $task): RedirectResponse
    {
        return $this->attempt(fn () => $this->tasks->updateDetails($task, $request->validated(), $request->user()),
            $task, 'Task details updated.', withInput: true);
    }

    public function reassign(ReassignTaskRequest $request, Task $task): RedirectResponse
    {
        $assignee = User::findOrFail($request->validated('assigned_to'));

        return $this->attempt(fn () => $this->tasks->reassign($task, $assignee, $request->user()),
            $task, "{$task->code()} is now assigned to {$assignee->name}.");
    }

    public function reopen(ReopenTaskRequest $request, Task $task): RedirectResponse
    {
        return $this->attempt(fn () => $this->tasks->reopen($task, $request->user(), (int) $request->validated('progress'), $request->validated('reason')),
            $task, "{$task->code()} was reopened.");
    }

    public function archive(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('archive', $task);

        return $this->attempt(fn () => $this->tasks->archive($task, $request->user()), $task, "{$task->code()} was archived.");
    }

    public function unarchive(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('archive', $task);

        return $this->attempt(fn () => $this->tasks->unarchive($task, $request->user()), $task, "{$task->code()} was restored from the archive.");
    }

    public function destroy(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('delete', $task);

        try {
            $this->tasks->trash($task, $request->user());
        } catch (TaskException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.tasks.index', ['view' => 'trash'])
            ->with('success', "{$task->code()} was moved to the trash. Its history is kept and it can be restored.");
    }

    public function restore(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('restore', $task);

        return $this->attempt(fn () => $this->tasks->restore($task, $request->user()), $task, "{$task->code()} was restored from the trash.");
    }

    private function attempt(callable $action, Task $task, string $success, bool $withInput = false): RedirectResponse
    {
        try {
            $action();
        } catch (TaskException $e) {
            $back = back()->withErrors([$e->field => $e->getMessage()])->with('error', $e->getMessage());

            return $withInput ? $back->withInput() : $back;
        }

        return redirect()->route('admin.tasks.show', $task)->with('success', $success);
    }

    /**
     * Eligible assignees for the select: name, membership no., level and account status.
     * Members only; no payment or contact details.
     *
     * @return array<int, string>
     */
    private function assigneeOptions(?int $except = null): array
    {
        return User::eligibleForTasks()
            ->with('member.level')
            ->when($except, fn ($q, $id) => $q->whereKeyNot($id))
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $u) => [$u->id => $u->name.' — '.($u->member->membership_no ?? 'no membership no.').' · '.$u->member->level->name.' · active member account'])
            ->all();
    }

    private function memberOptions(): array
    {
        return Member::query()->orderBy('last_name')->orderBy('first_name')->limit(500)->get()
            ->mapWithKeys(fn (Member $m) => [$m->id => $m->sortableName().' · '.$m->membership_no])->all();
    }
}
