@props(['updates'])
{{-- Activity timeline for a task (oldest first). Each entry shows who, what and when. --}}
@if ($updates->isEmpty())
    <x-empty-state icon="clock" title="No activity yet"/>
@else
    <ol class="relative mt-5 space-y-5 border-l-2 border-line pl-6">
        @foreach ($updates as $update)
            <li class="relative">
                <span @class(['absolute -left-[31px] top-1 size-3.5 rounded-full border-2 border-white',
                              'bg-lime-deep' => $update->color() === 'green',
                              'bg-amber-400' => $update->color() === 'amber',
                              'bg-sky-500' => $update->color() === 'blue',
                              'bg-lime' => $update->color() === 'lime',
                              'bg-muted' => $update->color() === 'gray'])></span>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <x-badge :color="$update->color()">{{ $update->label() }}</x-badge>
                    <span class="font-bold">{{ $update->user?->name ?? 'System' }}</span>
                    <time class="text-xs text-muted" datetime="{{ $update->created_at->toIso8601String() }}">
                        {{ $update->created_at->format('M j, Y g:i A') }}
                    </time>
                </div>
                @if ($update->old_status && $update->new_status && $update->old_status !== $update->new_status)
                    <p class="mt-1 text-sm">Status: {{ $update->old_status->label() }} → <strong>{{ $update->new_status->label() }}</strong></p>
                @endif
                @if ($update->old_progress !== null && $update->new_progress !== null && $update->old_progress !== $update->new_progress)
                    <p class="mt-1 text-sm">Progress: {{ $update->old_progress }}% → <strong>{{ $update->new_progress }}%</strong></p>
                @endif
                @if ($update->note)
                    <p class="mt-1 whitespace-pre-line break-words rounded-xl bg-canvas px-3 py-2 text-sm">{{ $update->note }}</p>
                @endif
            </li>
        @endforeach
    </ol>
@endif
