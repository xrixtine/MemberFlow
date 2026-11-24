<?php

namespace App\Http\Requests;

use App\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff progress update. Authorization uses TaskPolicy::updateProgress
 * (current assignee, active staff, task not archived/completed/deleted).
 */
class StoreTaskProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateProgress', $this->route('task'));
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TaskStatus::class)],
            'progress' => ['required', 'integer', 'between:0,100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['progress.between' => 'Progress must be between 0 and 100%.'];
    }
}
