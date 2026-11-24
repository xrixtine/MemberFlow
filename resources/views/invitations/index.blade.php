@php use App\Enums\InvitationStatus; @endphp
<x-layouts.app title="Invitations">
    <x-page-header eyebrow="Accounts" title="Account invitations"
                   subtitle="Member-account activation invitations (send one from a member's page). Links expire after 72 hours and work once. Older staff invitations are kept as history.">
        <a href="{{ route('admin.members.index') }}" class="btn-secondary">Members</a>
        <a href="{{ route('admin.users.index') }}" class="btn-secondary">Users</a>
    </x-page-header>

    <form method="GET" action="{{ route('admin.invitations.index') }}" class="card mb-6 grid gap-4 md:grid-cols-3" role="search">
        <div class="md:col-span-2">
            <label for="q" class="form-label">Search</label>
            <input id="q" type="search" name="q" value="{{ $q }}" class="form-input" placeholder="Name or email">
        </div>
        <x-form.select name="status" label="Status" :options="InvitationStatus::options()" :value="$status?->value" placeholder="All"/>
        <div class="flex gap-2 md:col-span-3">
            <button type="submit" class="btn-dark">Apply filters</button>
            @if ($q !== '' || $status)<a href="{{ route('admin.invitations.index') }}" class="btn-secondary">Clear</a>@endif
        </div>
    </form>

    @if ($invitations->isEmpty())
        <div class="card">
            <x-empty-state icon="user-plus" :title="$q !== '' || $status ? 'No invitations match your filters' : 'No invitations yet'"
                           message="Send a member an activation invitation from their page under Members.">
                <a href="{{ route('admin.members.index') }}" class="btn-primary">Members</a>
            </x-empty-state>
        </div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr><th>Invitee</th><th>Type</th><th>Status</th><th>Invited</th><th>Expires</th><th>Sent</th><th>Invited by</th><th><span class="sr-only">Actions</span></th></tr>
                </thead>
                <tbody>
                @foreach ($invitations as $invitation)
                    @php $effective = $invitation->effectiveStatus(); @endphp
                    <tr>
                        <td><span class="font-bold">{{ $invitation->name }}</span><span class="block text-xs text-muted">{{ $invitation->email }}</span></td>
                        <td class="text-xs">{{ $invitation->role === \App\Enums\UserRole::Member ? 'Member account' : 'Staff (historical)' }}</td>
                        <td>
                            <x-badge :status="$effective"/>
                            @if ($effective === InvitationStatus::Accepted && $invitation->acceptedUser)
                                <span class="block text-xs text-muted">{{ $invitation->accepted_at->format('M j, Y') }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap">{{ $invitation->created_at->format('M j, Y') }}</td>
                        <td class="whitespace-nowrap">{{ $invitation->expires_at->format('M j, Y g:i A') }}</td>
                        <td>{{ $invitation->send_count }}×</td>
                        <td>{{ $invitation->inviter?->name ?? 'MemberFlow (automatic)' }}</td>
                        <td class="text-right"><a href="{{ route('admin.invitations.show', $invitation) }}" class="btn-secondary btn-sm">Details</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $invitations->links() }}
    @endif
</x-layouts.app>
