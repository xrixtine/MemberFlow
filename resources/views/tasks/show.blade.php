<x-layouts.app :title="$task->code()">
    <x-page-header :eyebrow="'Task · '.$task->code()" :title="$task->title"
                   :subtitle="'Created '.$task->created_at->format('F j, Y').' by '.($task->creator?->name ?? 'unknown')">
        <a href="{{ route('admin.tasks.index') }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Tasks</a>
        @if (! $task->trashed() && ! $task->isArchived())
            <a href="{{ route('admin.tasks.edit', $task) }}" class="btn-dark">Edit</a>
        @endif
    </x-page-header>

    @if ($task->trashed())
        <div class="alert-warning mb-6" role="status"><x-icon name="alert" class="mt-0.5 size-4 shrink-0"/>
            <p>This task is in the trash (deleted {{ $task->deleted_at->format('M j, Y') }}). Its history is preserved. Restore it to make changes.</p></div>
    @elseif ($task->isArchived())
        <div class="alert-info mb-6" role="status"><x-icon name="alert" class="mt-0.5 size-4 shrink-0"/>
            <p>Archived {{ $task->archived_at->format('M j, Y') }}{{ $task->archiver ? ' by '.$task->archiver->name : '' }}. The assignee can view it but not update it.</p></div>
    @endif

    @if (! $task->trashed() && $assigneeBlocker)
        <div class="alert-warning mb-6" role="status" data-assignee-ineligible><x-icon name="alert" class="mt-0.5 size-4 shrink-0"/>
            <p><strong>The assignee can no longer work on this task.</strong> {{ $assigneeBlocker }}
                @unless ($task->isArchived()) Reassign it to an eligible member below. @endunless</p></div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <section class="card" aria-labelledby="overview-heading">
                <h2 id="overview-heading" class="sr-only">Overview</h2>
                <div class="flex flex-wrap items-center gap-2">
                    <x-badge :status="$task->status" class="!text-xs"/>
                    <x-badge :status="$task->priority"/>
                    @if ($task->isOverdue())<span class="badge-red">Overdue</span>@endif
                </div>
                <x-progress-bar :value="$task->progress" label="Progress" class="mt-5"/>
                <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs font-bold uppercase text-muted">Assignee</dt>
                        <dd class="mt-1">{{ $task->assignee?->name ?? '—' }}
                            @if ($task->assignee?->isMember() && $task->assignee->member)<span class="block font-mono text-xs text-muted">{{ $task->assignee->member->membership_no }}</span>@endif
                            @if ($task->assignee?->isFormerStaff())<span class="badge-gray">Former staff</span>@endif
                            @if ($task->assignee && ! $task->assignee->is_active)<span class="badge-gray">Inactive</span>@endif</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Due date</dt>
                        <dd @class(['mt-1', 'font-bold text-red-700' => $task->isOverdue()])>{{ $task->due_date?->format('F j, Y') ?? 'No due date' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Completed</dt>
                        <dd class="mt-1">{{ $task->completed_at?->format('M j, Y g:i A') ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Related member</dt>
                        <dd class="mt-1">
                            @if ($task->member && ! $task->member->trashed())
                                <a class="hover:underline" href="{{ route('admin.members.show', $task->member) }}">{{ $task->member->fullName() }}</a>
                            @else {{ $task->member?->fullName() ?? '—' }} @endif
                        </dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Last updated</dt><dd class="mt-1">{{ $task->updated_at->format('M j, Y g:i A') }}</dd></div>
                </dl>
                @if ($task->description)
                    <p class="mt-5 whitespace-pre-line break-words text-sm leading-relaxed">{{ $task->description }}</p>
                @endif
            </section>

            <section class="card" id="assignments" aria-labelledby="assignments-heading">
                <h2 id="assignments-heading" class="card-title">Assignment history</h2>
                <p class="card-subtitle">Every assignment period is kept. Previous assignees keep read-only access.</p>
                <x-task-assignments :assignments="$task->assignments"/>
            </section>

            <section class="card" id="activity" aria-labelledby="activity-heading">
                <h2 id="activity-heading" class="card-title">Activity timeline</h2>
                <x-task-timeline :updates="$task->updates"/>
            </section>
        </div>

        <aside class="space-y-6">
            @if ($task->trashed())
                <section class="card">
                    <h2 class="card-title">Trash</h2>
                    <form method="POST" action="{{ route('admin.tasks.restore', $task) }}" class="mt-4" data-confirm="Restore {{ $task->code() }} from the trash?">
                        @csrf @method('PATCH')
                        <button type="submit" class="btn-primary w-full">Restore task</button>
                    </form>
                </section>
            @else
                @unless ($task->isArchived() || $task->isCompleted())
                    <section class="card" aria-labelledby="reassign-heading">
                        <h2 id="reassign-heading" class="card-title">{{ $task->assignee ? 'Reassign' : 'Assign' }}</h2>
                        @if (empty($assignees))
                            <p class="mt-2 text-sm text-muted">No other eligible members are available (active account and an active, current membership).</p>
                        @else
                            <form method="POST" action="{{ route('admin.tasks.reassign', $task) }}" class="mt-4 space-y-4"
                                  data-confirm="Reassign {{ $task->code() }}? The current assignee will keep read-only access.">
                                @csrf @method('PATCH')
                                <x-form.select name="assigned_to" label="New assignee" required placeholder="Choose a member"
                                               :options="$assignees" hint="Eligible members only."/>
                                <button type="submit" class="btn-dark w-full">{{ $task->assignee ? 'Reassign task' : 'Assign task' }}</button>
                            </form>
                        @endif
                    </section>
                @endunless

                @if ($task->isCompleted() && ! $task->isArchived())
                    <section class="card" aria-labelledby="reopen-heading">
                        <h2 id="reopen-heading" class="card-title">Reopen task</h2>
                        <form method="POST" action="{{ route('admin.tasks.reopen', $task) }}" class="mt-4 space-y-4"
                              data-confirm="Reopen {{ $task->code() }}? It returns to In Progress.">
                            @csrf @method('PATCH')
                            <x-form.input name="progress" type="number" label="Progress after reopening (%)" value="90" min="0" max="99" required/>
                            <x-form.textarea name="reason" label="Reason" rows="2" required maxlength="1000"/>
                            <button type="submit" class="btn-primary w-full">Reopen task</button>
                        </form>
                    </section>
                @endif

                <section class="card" aria-labelledby="manage-heading">
                    <h2 id="manage-heading" class="card-title">Manage</h2>
                    <div class="mt-4 space-y-3">
                        @if ($task->isArchived())
                            <form method="POST" action="{{ route('admin.tasks.unarchive', $task) }}" data-confirm="Restore {{ $task->code() }} from the archive?">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn-primary w-full">Restore from archive</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.tasks.archive', $task) }}" data-confirm="Archive {{ $task->code() }}? The assignee will no longer be able to update it.">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn-secondary w-full">Archive task</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('admin.tasks.destroy', $task) }}" data-confirm="Move {{ $task->code() }} to the trash? Its history is kept and it can be restored.">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn-danger w-full">Move to trash</button>
                        </form>
                    </div>
                </section>
            @endif
        </aside>
    </div>
</x-layouts.app>
