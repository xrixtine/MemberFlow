@php $isSelf = $user->is(auth()->user()); @endphp
<x-layouts.app :title="'Manage '.$user->name">
    <x-page-header eyebrow="User management" :title="$user->name" :subtitle="$user->email">
        <a href="{{ route('admin.users.index') }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Users</a>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <section class="card" aria-labelledby="status-heading">
                <h2 id="status-heading" class="card-title">Account status</h2>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <x-badge :status="$user->role" class="!text-xs"/>
                    @if ($user->is_active)<span class="badge-green !text-xs">Active</span>@else<span class="badge-gray !text-xs">Inactive — cannot sign in</span>@endif
                    @if ($user->email_verified_at)<span class="badge-blue">Email confirmed {{ $user->email_verified_at->format('M j, Y') }}</span>@endif
                </div>
                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs font-bold uppercase text-muted">Joined</dt><dd class="mt-1">{{ $user->created_at->format('M j, Y') }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Activation</dt>
                        <dd class="mt-1">
                            @if ($user->acceptedInvitation)
                                <a class="hover:underline" href="{{ route('admin.invitations.show', $user->acceptedInvitation) }}">Invitation</a>
                                accepted {{ $user->acceptedInvitation->accepted_at->format('M j, Y') }}
                            @elseif ($user->isAdmin())
                                Initial account (seeded)
                            @else
                                Created {{ $user->created_at->format('M j, Y') }}
                            @endif
                        </dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Open tasks</dt><dd class="mt-1">{{ $openTasks }}</dd></div>
                </dl>
            </section>

            <section class="card" aria-labelledby="details-heading">
                <h2 id="details-heading" class="card-title">Details</h2>
                @if ($user->isMember())
                    <p class="mt-3 text-sm text-muted">
                        This is a member login account. Its name and sign-in email belong to the membership record
                        @if ($user->member)
                            <a class="font-bold text-ink hover:underline" href="{{ route('admin.members.show', $user->member) }}">{{ $user->member->fullName() }} · {{ $user->member->membership_no ?? 'no membership no. yet' }}</a>@if ($user->member->trashed()) (deleted)@endif.
                        @endif
                        Member accounts cannot be promoted to administrator.
                    </p>
                @else
                    <form method="POST" action="{{ route('admin.users.update', $user) }}" class="mt-5 space-y-5" novalidate>
                        @csrf @method('PUT')
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-form.input name="name" label="Name" :value="$user->name" required maxlength="255"/>
                            <x-form.input name="email" type="email" label="Email" :value="$user->email" required maxlength="255"/>
                        </div>
                        <button type="submit" class="btn-primary" data-loading="Saving…">Save details</button>
                    </form>
                @endif
            </section>

            <section class="card" aria-labelledby="audit-heading">
                <h2 id="audit-heading" class="card-title">Account history</h2>
                @if ($user->accountEvents->isEmpty())
                    <p class="mt-3 text-sm text-muted">No recorded account changes.</p>
                @else
                    <ul class="mt-4 divide-y divide-line">
                        @foreach ($user->accountEvents as $event)
                            <li class="flex flex-col gap-1 py-3 text-sm sm:flex-row sm:justify-between">
                                <span><strong>{{ $event->label() }}</strong>
                                    @if ($event->old_value || $event->new_value) ({{ $event->old_value }} → {{ $event->new_value }}) @endif
                                    · by {{ $event->actor?->name ?? 'the user (self-activation)' }}
                                    @if ($event->note)<span class="block text-muted">{{ $event->note }}</span>@endif</span>
                                <time class="whitespace-nowrap text-xs text-muted">{{ $event->created_at->format('M j, Y g:i A') }}</time>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <aside class="space-y-6">
            <section class="card" aria-labelledby="access-heading">
                <h2 id="access-heading" class="card-title">Access</h2>
                @if ($isSelf)
                    <p class="mt-2 text-sm text-muted">You cannot deactivate or change the role of your own account.</p>
                @elseif ($user->is_active)
                    @if ($user->isAdmin() && $activeAdmins <= 1)
                        <p class="mt-2 text-sm text-muted">This is the last active administrator and cannot be deactivated.</p>
                    @else
                        <form method="POST" action="{{ route('admin.users.deactivate', $user) }}" class="mt-4"
                              data-confirm="Deactivate {{ $user->name }}? They will be signed out and cannot sign in. Their history is kept.">
                            @csrf @method('PATCH')
                            <button type="submit" class="btn-danger w-full">Deactivate account</button>
                        </form>
                        @if ($openTasks > 0)<p class="mt-2 text-xs text-muted">{{ $openTasks }} open {{ str('task')->plural($openTasks) }} will stay assigned until you reassign them.</p>@endif
                    @endif
                @else
                    <form method="POST" action="{{ route('admin.users.reactivate', $user) }}" class="mt-4" data-confirm="Reactivate {{ $user->name }}?">
                        @csrf @method('PATCH')
                        <button type="submit" class="btn-primary w-full">Reactivate account</button>
                    </form>
                @endif
            </section>

            @if ($user->isFormerStaff())
                <section class="card" aria-labelledby="retired-heading">
                    <h2 id="retired-heading" class="card-title">Retired staff account</h2>
                    <p class="mt-2 text-sm text-muted">The staff role was retired. This account cannot sign in; it is kept because tasks,
                        invitations and the audit history refer to it.</p>
                </section>
            @endif
            <p class="text-xs text-muted">New administrators are created only with <code>php artisan memberflow:create-admin</code> on the server.</p>
        </aside>
    </div>
</x-layouts.app>
