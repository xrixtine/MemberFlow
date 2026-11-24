<x-layouts.app title="Levels">
    <x-page-header eyebrow="Membership" title="Membership levels"
                   subtitle="Each level sets the fee and validity period. Fee changes apply to new transactions only.">
        <a href="{{ route('admin.levels.create') }}" class="btn-primary"><x-icon name="layers" class="size-4"/> New level</a>
    </x-page-header>

    @if ($levels->isEmpty())
        <div class="card">
            <x-empty-state icon="layers" title="No levels yet" message="Create a level such as Basic or Gold with its fee and validity.">
                <a href="{{ route('admin.levels.create') }}" class="btn-primary">New level</a>
            </x-empty-state>
        </div>
    @else
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($levels as $level)
                <article @class(['flex flex-col rounded-2xl border bg-white p-6', 'border-line' => $level->is_active, 'border-dashed border-line opacity-75' => ! $level->is_active])>
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="text-xl font-bold">{{ $level->name }}</h2>
                        <x-badge :color="$level->is_active ? 'green' : 'gray'">{{ $level->is_active ? 'Active' : 'Inactive' }}</x-badge>
                    </div>
                    <p class="mt-3 text-3xl font-bold tracking-tight">{{ $level->formattedFee() }}</p>
                    <p class="text-sm text-muted">for {{ $level->validityLabel() }}</p>

                    <ul class="mt-4 flex-1 space-y-1.5 text-sm">
                        @forelse ($level->benefitList() as $benefit)
                            <li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-lime-deep"/> {{ $benefit }}</li>
                        @empty
                            <li class="text-muted">No benefits listed.</li>
                        @endforelse
                    </ul>

                    <p class="mt-4 border-t border-line pt-3 text-xs text-muted">
                        <a class="hover:underline" href="{{ route('admin.members.index', ['level' => $level->id]) }}">{{ $level->members_count }} {{ str('member')->plural($level->members_count) }}</a>
                        · {{ $level->transactions_count }} {{ str('transaction')->plural($level->transactions_count) }}
                    </p>
                    <div class="mt-3 flex gap-2">
                        <a href="{{ route('admin.levels.edit', $level) }}" class="btn-secondary btn-sm flex-1">Edit</a>
                        @if ($level->members_count === 0 && $level->transactions_count === 0)
                            <form method="POST" action="{{ route('admin.levels.destroy', $level) }}"
                                  data-confirm="Delete the level &quot;{{ $level->name }}&quot;?">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn-danger btn-sm">Delete</button>
                            </form>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
        <p class="mt-4 text-xs text-muted">Levels in use cannot be deleted — deactivate them instead. Transactions always keep the fee that applied when they were created.</p>
    @endif
</x-layouts.app>
