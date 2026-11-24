@php use App\Enums\TaskPriority; @endphp
{{-- Shared task form. Expects $task, $members; $assignees only when creating. --}}
<div class="card max-w-3xl space-y-5">
    <x-form.input name="title" label="Title" :value="$task->title" required maxlength="150" placeholder="e.g. Verify documents for new Corporate members"/>
    <x-form.textarea name="description" label="Description" :value="$task->description" rows="5" maxlength="5000"
                     hint="What needs to be done, and what counts as finished?"/>

    <div class="grid gap-5 sm:grid-cols-2">
        <x-form.select name="priority" label="Priority" required :options="TaskPriority::options()" :value="$task->priority?->value ?? 'medium'"/>
        <x-form.input name="due_date" type="date" label="Due date" :value="$task->due_date?->format('Y-m-d')"
                      :min="$task->exists ? null : now()->format('Y-m-d')" hint="Optional."/>
    </div>

    @isset($assignees)
        @if (empty($assignees))
            <div class="alert-warning" role="alert">
                <x-icon name="alert" class="mt-0.5 size-4 shrink-0"/>
                <p>No one can receive tasks yet. Tasks go to members with an <strong>active, current membership</strong> and an activated login account.</p>
            </div>
        @endif
        <x-form.select name="assigned_to" label="Assign to" required placeholder="Choose a member"
                       :options="$assignees" hint="Only members with an active, current membership and an active login account are listed."/>
    @endisset

    <x-form.select name="member_id" label="Related member" :options="$members" :value="$task->member_id ?? ($preselectedMember ?? null)"
                   placeholder="None" hint="Optional: link the task to a member record. The assignee sees only that member's name, never their profile or payments."/>
</div>
