<?php

namespace Tests\Feature\Tasks;

use App\Enums\TaskPriority;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class TaskTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $ana;   // member account

    protected User $ben;   // member account

    protected TaskService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(9, 0));

        $this->admin = User::factory()->admin()->create(['name' => 'Admin Alvarez']);
        $this->ana = User::factory()->member()->create(['name' => 'Ana Staff']);
        $this->ben = User::factory()->member()->create(['name' => 'Ben Staff']);
        $this->service = app(TaskService::class);
    }

    /** Create a task through the real service (assignment + activity rows). */
    protected function task(array $data = [], ?User $assignee = null): Task
    {
        return $this->service->create(array_merge([
            'title' => 'Verify member documents',
            'description' => 'Check IDs for new applications.',
            'priority' => TaskPriority::Medium->value,
            'due_date' => '2026-11-20',
        ], $data), $assignee ?? $this->ana, $this->admin);
    }

    protected function progress(Task $task, User $user, array $input)
    {
        return $this->service->recordProgress($task, $user, $input);
    }
}
