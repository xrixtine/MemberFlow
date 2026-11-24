<?php

namespace App\Http\Requests;

use App\Enums\TaskPriority;
use App\Models\Task;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (with assignee) and edit (details only) a task.
 */
class SaveTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof Task
            ? $this->user()->can('update', $task)
            : $this->user()->can('create', Task::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => is_string($this->input('title')) ? trim($this->input('title')) : $this->input('title'),
            'description' => is_string($this->input('description')) && trim($this->input('description')) === '' ? null : $this->input('description'),
            'member_id' => $this->input('member_id') ?: null,
            'due_date' => $this->input('due_date') ?: null,
        ]);
    }

    public function rules(): array
    {
        $creating = ! $this->route('task') instanceof Task;

        $rules = [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'due_date' => array_filter(['nullable', 'date_format:Y-m-d', $creating ? 'after_or_equal:today' : null]),
            'member_id' => ['nullable', 'integer', Rule::exists('members', 'id')->whereNull('deleted_at')],
        ];

        if ($creating) {
            $rules['assigned_to'] = ['required', 'integer', self::eligibleAssigneeRule()];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'assigned_to.required' => 'Choose who to assign this task to.',
            'due_date.after_or_equal' => 'The due date cannot be in the past.',
            'member_id.exists' => 'Choose an existing member, or leave it empty.',
        ];
    }

    /**
     * Server-side eligibility (never only the dropdown): an active member account with
     * a current, active membership — or, during the transition, an active staff account.
     * TaskService re-checks the same rule under row locks.
     */
    public static function eligibleAssigneeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! User::eligibleForTasks()->whereKey((int) $value)->exists()) {
                $fail('Choose a member with an active membership and an active account.');
            }
        };
    }
}
