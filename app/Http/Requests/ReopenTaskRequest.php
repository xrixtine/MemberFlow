<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReopenTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reopen', $this->route('task'));
    }

    public function rules(): array
    {
        return [
            'progress' => ['required', 'integer', 'between:0,99'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'progress.between' => 'A reopened task must be below 100%.',
            'reason.required' => 'Explain why the task is being reopened.',
        ];
    }
}
