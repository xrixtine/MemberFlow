<x-layouts.app title="New task">
    <x-page-header eyebrow="Tasks" title="New task" subtitle="The assignee sees it immediately under My Tasks.">
        <a href="{{ route('admin.tasks.index') }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Tasks</a>
    </x-page-header>

    <form method="POST" action="{{ route('admin.tasks.store') }}" novalidate>
        @csrf
        @include('tasks._form')
        <div class="mt-6 flex gap-3">
            <button type="submit" class="btn-primary" data-loading="Creating…">Create and assign</button>
            <a href="{{ route('admin.tasks.index') }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
</x-layouts.app>
