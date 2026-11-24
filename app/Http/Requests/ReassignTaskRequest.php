<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Only "assigned_to" is accepted; TaskPolicy::assign is administrator-only. */
class ReassignTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assign', $this->route('task'));
    }

    public function rules(): array
    {
        return ['assigned_to' => ['required', 'integer', SaveTaskRequest::eligibleAssigneeRule()]];
    }

    public function messages(): array
    {
        return ['assigned_to.required' => 'Choose who to reassign this task to.'];
    }
}
