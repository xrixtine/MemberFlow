<x-layouts.app :title="'Edit '.$task->code()">
    <x-page-header :eyebrow="'Tasks · '.$task->code()" title="Edit task" subtitle="Status and progress are updated by the assignee; use Reassign on the task page to change the assignee.">
        <a href="{{ route('admin.tasks.show', $task) }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Back to task</a>
    </x-page-header>

    <form method="POST" action="{{ route('admin.tasks.update', $task) }}" novalidate>
        @csrf
        @method('PUT')
        @include('tasks._form')
        <div class="mt-6 flex gap-3">
            <button type="submit" class="btn-primary" data-loading="Saving…">Save changes</button>
            <a href="{{ route('admin.tasks.show', $task) }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
</x-layouts.app>
