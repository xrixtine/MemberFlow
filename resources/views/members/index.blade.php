<x-layouts.app title="Members">
    <x-page-header eyebrow="Membership" title="Member directory"
                   subtitle="Search by name, membership number or email, and filter by category, level, status or source.">
        <a href="{{ route('admin.members.create') }}" class="btn-primary"><x-icon name="user-plus" class="size-4"/> Register member</a>
    </x-page-header>

    {{-- Status summary --}}
    <div class="mb-6 flex flex-wrap gap-2" aria-label="Members by status">
        @foreach (\App\Enums\MemberStatus::cases() as $status)
            <a href="{{ route('admin.members.index', ['status' => $status->value]) }}"
               @class(['inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-bold',
                       'border-charcoal bg-charcoal text-lime' => $filters['status'] === $status,
                       'border-line bg-white text-ink hover:border-lime-deep' => $filters['status'] !== $status])>
                {{ $status->label() }} <span class="opacity-70">{{ $statusCounts[$status->value] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    {{-- Search & filters (GET form: shareable URLs, works without JS) --}}
    <form method="GET" action="{{ route('admin.members.index') }}" class="card mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-6" role="search">
        <div class="md:col-span-2">
            <label for="q" class="form-label">Search</label>
            <input id="q" type="search" name="q" value="{{ $filters['q'] }}" class="form-input" placeholder="Name, MF-number or email">
        </div>
        <x-form.select name="category" label="Category" :options="$categories->all()" :value="$filters['category']" placeholder="All categories"/>
        <x-form.select name="level" label="Level" :options="$levels->all()" :value="$filters['level']" placeholder="All levels"/>
        <x-form.select name="status" label="Status" :options="\App\Enums\MemberStatus::options()" :value="$filters['status']?->value" placeholder="All statuses"/>
        <x-form.select name="source" label="Source" :options="\App\Enums\MemberSource::options()" :value="$filters['source']?->value" placeholder="All sources"/>
        <div class="flex flex-wrap gap-2 md:col-span-2 xl:col-span-6">
            <button type="submit" class="btn-dark">Apply filters</button>
            @if ($hasFilters)
                <a href="{{ route('admin.members.index') }}" class="btn-secondary">Clear</a>
            @endif
        </div>
    </form>

    @if ($members->isEmpty())
        <div class="card">
            @if ($hasFilters)
                <x-empty-state icon="users" title="No members match your search"
                               message="Try a different name or clear the filters.">
                    <a href="{{ route('admin.members.index') }}" class="btn-secondary">Clear filters</a>
                </x-empty-state>
            @else
                <x-empty-state icon="users" title="No members yet" message="Register your first member to get started.">
                    <a href="{{ route('admin.members.create') }}" class="btn-primary">Register member</a>
                </x-empty-state>
            @endif
        </div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th scope="col">Member</th>
                    <th scope="col">Membership no.</th>
                    <th scope="col">Category</th>
                    <th scope="col">Level</th>
                    <th scope="col">Status</th>
                    <th scope="col">Valid until</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($members as $member)
                    <tr>
                        <td>
                            <a href="{{ route('admin.members.show', $member) }}" class="font-bold hover:underline">{{ $member->sortableName() }}</a>
                            <span class="block text-xs text-muted">{{ $member->email }}</span>
                            @if ($member->account)
                                <span @class(['mt-1 inline-block text-[11px] font-bold', 'text-green-700' => $member->account->is_active, 'text-muted' => ! $member->account->is_active])>
                                    {{ $member->account->is_active ? 'Login account active' : 'Login account deactivated' }}
                                </span>
                            @endif
                        </td>
                        <td class="font-mono text-xs">{{ $member->membership_no }}</td>
                        <td>{{ $member->category->name }}</td>
                        <td>{{ $member->level->name }}</td>
                        <td>
                            <x-badge :status="$member->status"/>
                            @if ($member->source === \App\Enums\MemberSource::Online)
                                <x-badge :status="$member->source" class="ml-1"/>
                                @unless ($member->isEmailVerified())
                                    <x-badge color="amber" class="ml-1">Unverified</x-badge>
                                @endunless
                            @endif
                        </td>
                        <td class="whitespace-nowrap">{{ $member->membership_end?->format('M j, Y') ?? '—' }}</td>
                        <td class="text-right">
                            <a href="{{ route('admin.members.show', $member) }}" class="btn-secondary btn-sm">View</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $members->links() }}
    @endif
</x-layouts.app>
