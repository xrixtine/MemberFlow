<x-layouts.app title="Task history">
    <x-page-header eyebrow="Your workspace" title="Task history" subtitle="Tasks you have completed or handled, and every update you recorded."/>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="card xl:col-span-1" aria-labelledby="completed-heading">
            <h2 id="completed-heading" class="card-title">Completed tasks</h2>
            @if ($completed->isEmpty())
                <x-empty-state icon="check" title="Nothing completed yet"/>
            @else
                <ul class="mt-4 divide-y divide-line">
                    @foreach ($completed as $task)
                        <li class="py-3">
                            <a href="{{ route('my.tasks.show', $task) }}" class="font-bold hover:underline">{{ $task->title }}</a>
                            <p class="text-xs text-muted">{{ $task->code() }} · completed {{ $task->completed_at?->format('M j, Y') }}
                                @if ($task->assignee && $task->assigned_to !== auth()->id()) · by {{ $task->assignee->name }} @endif</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="card xl:col-span-2" aria-labelledby="updates-heading">
            <h2 id="updates-heading" class="card-title">My updates</h2>
            @if ($updates->isEmpty())
                <x-empty-state icon="clock" title="No updates yet" message="Progress updates you record will be listed here."/>
            @else
                <ul class="mt-4 divide-y divide-line">
                    @foreach ($updates as $update)
                        <li class="flex flex-col gap-1 py-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <a href="{{ route('my.tasks.show', $update->task) }}" class="font-bold hover:underline">{{ $update->task->title }}</a>
                                <p class="text-sm">
                                    <x-badge :color="$update->color()">{{ $update->label() }}</x-badge>
                                    @if ($update->new_progress !== null && $update->old_progress !== $update->new_progress) {{ $update->old_progress }}% → {{ $update->new_progress }}% @endif
                                </p>
                                @if ($update->note)<p class="mt-1 text-sm text-muted">{{ $update->note }}</p>@endif
                            </div>
                            <time class="whitespace-nowrap text-xs text-muted">{{ $update->created_at->format('M j, Y g:i A') }}</time>
                        </li>
                    @endforeach
                </ul>
                {{ $updates->links() }}
            @endif
        </section>
    </div>
</x-layouts.app>
