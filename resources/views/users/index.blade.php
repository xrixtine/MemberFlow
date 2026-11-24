@php use App\Enums\UserRole; @endphp
<x-layouts.app title="Users">
    <x-page-header eyebrow="Accounts" title="User management"
                   subtitle="Login accounts: administrators and members. Members activate their account by invitation from their member page; accounts are deactivated, never deleted. Retired staff accounts are kept for history and cannot sign in.">
        <a href="{{ route('admin.invitations.index') }}" class="btn-secondary">Invitations</a>
        <a href="{{ route('admin.members.index') }}" class="btn-primary"><x-icon name="users" class="size-4"/> Members</a>
    </x-page-header>

    <form method="GET" action="{{ route('admin.users.index') }}" class="card mb-6 grid gap-4 md:grid-cols-4" role="search">
        <div class="md:col-span-2">
            <label for="q" class="form-label">Search</label>
            <input id="q" type="search" name="q" value="{{ $filters['q'] }}" class="form-input" placeholder="Name or email">
        </div>
        <x-form.select name="role" label="Role" :options="UserRole::options()" :value="$filters['role']?->value" placeholder="All roles"/>
        <x-form.select name="status" label="Account status" :options="['active' => 'Active', 'inactive' => 'Inactive']" :value="$filters['status']" placeholder="All"/>
        <div class="flex gap-2 md:col-span-4">
            <button type="submit" class="btn-dark">Apply filters</button>
            @if ($hasFilters)<a href="{{ route('admin.users.index') }}" class="btn-secondary">Clear</a>@endif
        </div>
    </form>

    @if ($users->isEmpty())
        <div class="card"><x-empty-state icon="user" title="No users match your filters"/></div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Activated</th><th class="text-right">Open tasks</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td>
                            <span class="font-bold">{{ $user->name }}</span>
                            @if ($user->is(auth()->user()))<span class="badge-lime ml-1">You</span>@endif
                            <span class="block text-xs text-muted">{{ $user->email }}</span>
                        </td>
                        <td><x-badge :status="$user->role"/></td>
                        <td>@if ($user->is_active)<span class="badge-green">Active</span>@else<span class="badge-gray">Inactive</span>@endif</td>
                        <td class="text-xs">
                            @if ($user->acceptedInvitation)
                                Invitation accepted {{ $user->acceptedInvitation->accepted_at->format('M j, Y') }}
                                <span class="block text-muted">invited by {{ $user->inviter?->name ?? 'unknown' }}</span>
                            @else
                                <span class="text-muted">Initial account (seeded)</span>
                            @endif
                        </td>
                        <td class="text-right">{{ $user->open_tasks_count }}</td>
                        <td class="text-right"><a href="{{ route('admin.users.edit', $user) }}" class="btn-secondary btn-sm">Manage</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $users->links() }}
    @endif
</x-layouts.app>
