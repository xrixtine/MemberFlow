<x-layouts.app title="New transaction">
    <x-page-header eyebrow="Transactions" title="New transaction" subtitle="Step 1 of 2 — find the member.">
        <a href="{{ route('admin.transactions.index') }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Back</a>
    </x-page-header>

    <form method="GET" action="{{ route('admin.transactions.create') }}" class="card mb-6 flex flex-col gap-3 sm:flex-row sm:items-end" role="search">
        <div class="flex-1">
            <label for="q" class="form-label">Member</label>
            <input id="q" type="search" name="q" value="{{ $q }}" class="form-input" placeholder="Name, MF-number or email" autofocus>
        </div>
        <button type="submit" class="btn-dark">Search</button>
    </form>

    @if ($q === '')
        <div class="card"><x-empty-state icon="users" title="Search for a member" message="Applications are for newly registered members; renewals are for active or expired memberships."/></div>
    @elseif ($candidates->isEmpty())
        <div class="card">
            <x-empty-state icon="users" title="No members found" message="Check the spelling, or register the member first.">
                <a href="{{ route('admin.members.create') }}" class="btn-primary">Register member</a>
            </x-empty-state>
        </div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Member</th><th>Level</th><th>Status</th><th>Valid until</th><th>Open transaction</th><th></th></tr></thead>
                <tbody>
                @foreach ($candidates as $candidate)
                    <tr>
                        <td><span class="font-bold">{{ $candidate->sortableName() }}</span><span class="block font-mono text-xs text-muted">{{ $candidate->membership_no }}</span></td>
                        <td>{{ $candidate->level->name }}</td>
                        <td><x-badge :status="$candidate->status"/></td>
                        <td>{{ $candidate->membership_end?->format('M j, Y') ?? '—' }}</td>
                        <td class="font-mono text-xs">{{ $candidate->openTransaction?->reference_no ?? '—' }}</td>
                        <td class="text-right"><a class="btn-secondary btn-sm" href="{{ route('admin.transactions.create', ['member' => $candidate->id]) }}">Select</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.app>
