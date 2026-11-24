@php use App\Enums\TaskStatus; @endphp
<x-layouts.app :title="$task->code()">
    <x-page-header :eyebrow="'My tasks · '.$task->code()" :title="$task->title"
                   :subtitle="'Assigned by '.($task->creator?->name ?? 'an administrator').' · created '.$task->created_at->format('M j, Y')">
        <a href="{{ route('my.tasks.index', $isCurrent ? [] : ['tab' => 'previous']) }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> My tasks</a>
    </x-page-header>

    @if (! $isCurrent)
        <div class="alert-info mb-6" role="status"><x-icon name="alert" class="mt-0.5 size-4 shrink-0"/>
            <p><strong>Read-only.</strong> This task has been reassigned{{ $task->assignee ? ' to '.$task->assignee->name : '' }}. You can view its history but not change it.</p></div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <section class="card" aria-labelledby="task-overview">
                <h2 id="task-overview" class="sr-only">Overview</h2>
                <div class="flex flex-wrap items-center gap-2">
                    <x-badge :status="$task->status" class="!text-xs"/>
                    <x-badge :status="$task->priority"/>
                    @if ($task->isOverdue())<span class="badge-red">Overdue</span>@endif
                    @if ($task->isArchived())<span class="badge-gray">Archived</span>@endif
                </div>
                <x-progress-bar :value="$task->progress" label="Progress" class="mt-5"/>
                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs font-bold uppercase text-muted">Due date</dt>
                        <dd @class(['mt-1', 'font-bold text-red-700' => $task->isOverdue()])>{{ $task->due_date?->format('F j, Y') ?? 'No due date' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Assigned to</dt><dd class="mt-1">{{ $task->assignee?->name ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Completed</dt><dd class="mt-1">{{ $task->completed_at?->format('M j, Y g:i A') ?? '—' }}</dd></div>
                    @if ($task->member)
                        {{-- Name only: the assignee gets no access to that member's profile, contact details or payments. --}}
                        <div data-related-member><dt class="text-xs font-bold uppercase text-muted">Related member</dt><dd class="mt-1">{{ $task->member->fullName() }}</dd></div>
                    @endif
                </dl>
                @if ($task->description)
                    <p class="mt-5 whitespace-pre-line break-words text-sm leading-relaxed">{{ $task->description }}</p>
                @endif
            </section>

            <section class="card" aria-labelledby="my-activity-heading">
                <h2 id="my-activity-heading" class="card-title">Activity timeline</h2>
                <x-task-timeline :updates="$task->updates"/>
            </section>

            <section class="card" aria-labelledby="my-assignments-heading">
                <h2 id="my-assignments-heading" class="card-title">Assignment history</h2>
                <x-task-assignments :assignments="$task->assignments"/>
            </section>
        </div>

        <aside>
            <section class="card" aria-labelledby="update-heading">
                <h2 id="update-heading" class="card-title">Update progress</h2>
                @if ($blocker)
                    <p class="mt-2 text-sm text-muted">{{ $blocker }}</p>
                @else
                    <p class="card-subtitle">Setting 100% marks the task completed; choosing “Completed” sets it to 100%.</p>
                    <form method="POST" action="{{ route('my.tasks.progress', $task) }}" class="mt-5 space-y-5" novalidate>
                        @csrf
                        <x-form.select name="status" label="Status" required :options="TaskStatus::options()" :value="$task->status->value"/>
                        <div>
                            <label for="progress" class="form-label">Progress: <output id="progress-output" for="progress">{{ old('progress', $task->progress) }}</output>%</label>
                            <input id="progress" type="range" name="progress" min="0" max="100" step="5" value="{{ old('progress', $task->progress) }}"
                                   class="w-full accent-[#5d7f24]" oninput="document.getElementById('progress-output').value = this.value"
                                   @error('progress') aria-invalid="true" aria-describedby="progress-error" @enderror>
                            @error('progress')<p id="progress-error" class="form-error">{{ $message }}</p>@enderror
                        </div>
                        <x-form.textarea name="note" label="Progress note" rows="3" maxlength="2000" hint="What did you do? Any blockers?"/>
                        <button type="submit" class="btn-primary w-full" data-loading="Saving…">Save update</button>
                    </form>
                @endif
            </section>
        </aside>
    </div>
</x-layouts.app>
