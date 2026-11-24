@props(['assignments'])
{{-- Assignment history: every assignment period, current one highlighted. --}}
@if ($assignments->isEmpty())
    <p class="mt-4 text-sm text-muted">Not assigned yet.</p>
@else
    <div class="mt-4 overflow-x-auto">
        <table class="data-table !min-w-0">
            <thead><tr><th>Assignee</th><th>Assigned by</th><th>From</th><th>Until</th></tr></thead>
            <tbody>
            @foreach ($assignments as $assignment)
                <tr @class(['bg-lime/15' => $assignment->isCurrent()])>
                    <td>
                        <span class="font-bold">{{ $assignment->user->name }}</span>
                        @if ($assignment->isCurrent())<span class="badge-lime ml-1">Current</span>@endif
                        @unless ($assignment->user->is_active)<span class="badge-gray ml-1">Inactive account</span>@endunless
                    </td>
                    <td>{{ $assignment->assigner->name }}</td>
                    <td class="whitespace-nowrap">{{ $assignment->assigned_at->format('M j, Y g:i A') }}</td>
                    <td class="whitespace-nowrap">{{ $assignment->unassigned_at?->format('M j, Y g:i A') ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
