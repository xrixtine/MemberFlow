@php use App\Enums\TaskStatus; @endphp
<x-layouts.app title="My Tasks">
    <x-page-header eyebrow="Your workspace" title="My tasks" subtitle="Tasks assigned to you, and tasks you handled before they were reassigned."/>

    @if ($readOnly)
        <div class="alert-info mb-6" role="status" data-tasks-read-only><x-icon name="alert" class="mt-0.5 size-4 shrink-0"/>
            <p><strong>Your tasks are read-only.</strong> Only members with an active membership can update tasks.
                You can still view your tasks and their history. Please contact the membership office.</p></div>
    @endif

    <nav class="mb-6 flex flex-wrap items-center gap-2" aria-label="Task lists">
        @foreach (['current' => 'Current assignments', 'previous' => 'Previously handled (read-only)'] as $key => $label)
            <a href="{{ route('my.tasks.index', $key === 'current' ? [] : ['tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif
               @class(['inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-sm font-bold',
                       'border-charcoal bg-charcoal text-lime' => $tab === $key,
                       'border-line bg-white hover:border-lime-deep' => $tab !== $key])>
                {{ $label }} <span class="opacity-70">{{ $counts[$key] }}</span>
            </a>
        @endforeach

        <form method="GET" action="{{ route('my.tasks.index') }}" class="ml-auto flex items-center gap-2">
            @if ($tab === 'previous')<input type="hidden" name="tab" value="previous">@endif
            <label for="status" class="sr-only">Status</label>
            <select id="status" name="status" class="form-input !py-2" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach (TaskStatus::cases() as $option)
                    <option value="{{ $option->value }}" @selected($status === $option)>{{ $option->label() }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="btn-secondary btn-sm">Filter</button></noscript>
        </form>
    </nav>

    @if ($tasks->isEmpty())
        <div class="card">
            <x-empty-state icon="check"
                :title="$tab === 'current' ? 'No tasks assigned to you' : 'No previously handled tasks'"
                :message="$tab === 'current' ? 'When an administrator assigns you a task, it will appear here.' : 'Tasks reassigned from you to someone else appear here, read-only.'"/>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($tasks as $task)
                <a href="{{ route('my.tasks.show', $task) }}"
                   @class(['flex flex-col rounded-2xl border bg-white p-5 transition-colors hover:border-lime-deep',
                           'border-line' => $tab === 'current', 'border-dashed border-line opacity-80' => $tab === 'previous'])>
                    <div class="flex items-start justify-between gap-2">
                        <span class="font-mono text-xs text-muted">{{ $task->code() }}</span>
                        <div class="flex flex-wrap justify-end gap-1">
                            @if ($tab === 'previous')<span class="badge-gray">Read-only</span>@endif
                            @if ($task->isArchived())<span class="badge-gray">Archived</span>@endif
                            <x-badge :status="$task->priority"/>
                        </div>
                    </div>
                    <h2 class="mt-2 font-bold">{{ $task->title }}</h2>
                    <div class="mt-3 flex items-center gap-2"><x-badge :status="$task->status"/>
                        @if ($task->isOverdue())<span class="badge-red">Overdue</span>@endif</div>
                    <x-progress-bar :value="$task->progress" label="Progress" class="mt-4"/>
                    <p class="mt-3 text-xs text-muted">
                        {{ $task->due_date ? 'Due '.$task->due_date->format('M j, Y') : 'No due date' }}
                        @if ($tab === 'previous') · now with {{ $task->assignee?->name ?? 'nobody' }} @endif
                    </p>
                </a>
            @endforeach
        </div>
        {{ $tasks->links() }}
    @endif
</x-layouts.app>
