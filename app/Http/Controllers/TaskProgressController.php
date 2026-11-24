<?php

namespace App\Http\Controllers;

use App\Exceptions\TaskException;
use App\Http\Requests\StoreTaskProgressRequest;
use App\Models\Task;
use App\Models\TaskProgressUpdate;
use App\Services\TaskService;
use Illuminate\Http\RedirectResponse;

/**
 * Staff progress updates on their current assignments.
 */
class TaskProgressController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    public function store(StoreTaskProgressRequest $request, Task $task): RedirectResponse
    {
        try {
            $update = $this->tasks->recordProgress($task, $request->user(), $request->validated());
        } catch (TaskException $e) {
            return back()->withInput()->withErrors([$e->field => $e->getMessage()]);
        }

        $message = $update->type === TaskProgressUpdate::COMPLETED
            ? 'Task marked as completed. Nice work!'
            : 'Progress update saved.';

        return redirect()->route('my.tasks.show', $task)->with('success', $message);
    }
}
