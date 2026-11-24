@php use App\Enums\TaskPriority; use App\Enums\TaskStatus; @endphp
<x-layouts.app title="Tasks">
    <x-page-header eyebrow="Team" title="Task management"
                   subtitle="Create, assign and monitor work for your members. Tasks are archived or moved to the trash — never permanently deleted.">
        <a href="{{ route('admin.tasks.create') }}" class="btn-primary"><x-icon name="tasks" class="size-4"/> New task</a>
    </x-page-header>

    {{-- Views: active / archived / trash --}}
    <nav class="mb-6 flex flex-wrap gap-2" aria-label="Task views">
        @foreach (['active' => 'Active', 'archived' => 'Archived', 'trash' => 'Trash'] as $key => $label)
            <a href="{{ route('admin.tasks.index', $key === 'active' ? [] : ['view' => $key]) }}"
               @if($view === $key) aria-current="page" @endif
               @class(['inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-sm font-bold',
                       'border-charcoal bg-charcoal text-lime' => $view === $key,
                       'border-line bg-white hover:border-lime-deep' => $view !== $key])>
                {{ $label }} <span class="opacity-70">{{ $counts[$key] }}</span>
            </a>
        @endforeach
    </nav>

    <form method="GET" action="{{ route('admin.tasks.index') }}" class="card mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-6" role="search">
        @if ($view !== 'active')<input type="hidden" name="view" value="{{ $view }}">@endif
        <div class="md:col-span-2">
            <label for="q" class="form-label">Search</label>
            <input id="q" type="search" name="q" value="{{ $filters['q'] }}" class="form-input" placeholder="Title or description">
        </div>
        <x-form.select name="status" label="Status" :options="TaskStatus::options()" :value="$filters['status']?->value" placeholder="All statuses"/>
        <x-form.select name="priority" label="Priority" :options="TaskPriority::options()" :value="$filters['priority']?->value" placeholder="All priorities"/>
        <x-form.select name="assignee" label="Assignee" :options="$assigneeFilter->mapWithKeys(fn ($u) => [$u->id => $u->name.($u->is_active ? '' : ' (inactive)')])->all()"
                       :value="$filters['assignee']" placeholder="Everyone"/>
        <x-form.select name="due" label="Due" :options="['overdue' => 'Overdue', 'today' => 'Due today', 'week' => 'Next 7 days', 'none' => 'No due date']"
                       :value="$filters['due']" placeholder="Any time"/>
        <div class="flex flex-wrap gap-2 md:col-span-2 xl:col-span-6">
            <button type="submit" class="btn-dark">Apply filters</button>
            @if ($hasFilters)
                <a href="{{ route('admin.tasks.index', $view === 'active' ? [] : ['view' => $view]) }}" class="btn-secondary">Clear</a>
            @endif
        </div>
    </form>

    @if ($tasks->isEmpty())
        <div class="card">
            @if ($hasFilters)
                <x-empty-state icon="tasks" title="No tasks match your filters" message="Try a different search or clear the filters."/>
            @elseif ($view === 'archived')
                <x-empty-state icon="tasks" title="No archived tasks" message="Archived tasks are kept here with their full history."/>
            @elseif ($view === 'trash')
                <x-empty-state icon="tasks" title="The trash is empty" message="Deleted tasks can be restored from here."/>
            @else
                <x-empty-state icon="tasks" title="No tasks yet" message="Create a task and assign it to a member with an active membership.">
                    <a href="{{ route('admin.tasks.create') }}" class="btn-primary">New task</a>
                </x-empty-state>
            @endif
        </div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th scope="col">Task</th>
                    <th scope="col">Assignee</th>
                    <th scope="col">Priority</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="w-40">Progress</th>
                    <th scope="col">Due</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($tasks as $task)
                    <tr>
                        <td>
                            <a href="{{ route('admin.tasks.show', $task) }}" class="font-bold hover:underline">{{ $task->title }}</a>
                            <span class="block font-mono text-xs text-muted">{{ $task->code() }}@if ($task->member) · {{ $task->member->fullName() }}@endif</span>
                        </td>
                        <td>
                            {{ $task->assignee?->name ?? '—' }}
                            @if ($task->assignee && ! $task->assignee->is_active)<span class="badge-gray">Inactive</span>@endif
                        </td>
                        <td><x-badge :status="$task->priority"/></td>
                        <td><x-badge :status="$task->status"/></td>
                        <td><x-progress-bar :value="$task->progress" :label="null"/><span class="text-xs text-muted">{{ $task->progress }}%</span></td>
                        <td class="whitespace-nowrap">
                            @if ($task->due_date)
                                <span @class(['font-bold text-red-700' => $task->isOverdue()])>{{ $task->due_date->format('M j, Y') }}</span>
                                @if ($task->isOverdue())<span class="badge-red ml-1">Overdue</span>@endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-right"><a href="{{ route('admin.tasks.show', $task) }}" class="btn-secondary btn-sm">Open</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $tasks->links() }}
    @endif
</x-layouts.app>
