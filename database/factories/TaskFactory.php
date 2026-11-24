<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => ucfirst(fake()->words(4, true)),
            'description' => fake()->sentence(),
            'priority' => TaskPriority::Medium,
            'status' => TaskStatus::Todo,
            'progress' => 0,
            'due_date' => today()->addWeek(),
            'assigned_to' => User::factory(),   // a member account (the only role that works on tasks)
            'created_by' => User::factory()->admin(),
        ];
    }
}
