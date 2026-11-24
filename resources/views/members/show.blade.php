@php use App\Enums\MemberStatus; use App\Support\Money; @endphp
<x-layouts.app :title="$member->fullName()">
    <x-page-header :eyebrow="'Member · '.$member->membership_no" :title="$member->fullName()"
                   :subtitle="'Registered '.$member->created_at->format('F j, Y').($member->creator ? ' by '.$member->creator->name : ' (online)')">
        <a href="{{ route('admin.members.index') }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Directory</a>
        <a href="{{ route('admin.members.history', $member) }}" class="btn-secondary">Transaction history</a>
        <a href="{{ route('admin.members.print', $member) }}" class="btn-secondary" target="_blank" rel="noopener">Print record</a>
        <a href="{{ route('admin.members.edit', $member) }}" class="btn-dark">Edit</a>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            {{-- Status banner --}}
            <section class="card flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between" aria-label="Membership status">
                <div class="flex flex-wrap items-center gap-2">
                    <x-badge :status="$member->status" class="!text-xs"/>
                    <x-badge :status="$member->source"/>
                    @if ($member->isEmailVerified())
                        <x-badge color="green">Email verified {{ $member->email_verified_at->format('M j, Y') }}</x-badge>
                    @else
                        <x-badge color="amber">Email not verified</x-badge>
                    @endif
                </div>
                <p class="text-sm text-muted">
                    @if ($member->membership_start)
                        Valid {{ $member->membership_start->format('M j, Y') }} – {{ $member->membership_end->format('M j, Y') }}
                    @else
                        Not yet active — activates when a fully paid application is approved.
                    @endif
                </p>
            </section>

            <section class="card" aria-labelledby="details-heading">
                <h2 id="details-heading" class="card-title">Member details</h2>
                <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    @foreach ([
                        'Membership no.' => $member->membership_no,
                        'Email' => $member->email,
                        'Phone' => $member->phone,
                        'Birthdate' => $member->birthdate?->format('F j, Y'),
                        'Address' => $member->address,
                        'Coordinates' => $member->hasLocation() ? $member->latitude.', '.$member->longitude : null,
                        'Category' => $member->category->name,
                        'Level' => $member->level->name,
                    ] as $label => $value)
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wider text-muted">{{ $label }}</dt>
                            <dd class="mt-1 break-words">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <section class="card" aria-labelledby="history-heading">
                <h2 id="history-heading" class="card-title">Transaction history</h2>
                <p class="card-subtitle">Applications and renewals for this member. Amounts are the fees recorded at the time.</p>

                @if ($member->transactions->isEmpty())
                    <x-empty-state icon="receipt" title="No transactions yet"
                                   message="Applications and renewals for this member will be listed here."/>
                @else
                    <div class="mt-5 overflow-x-auto">
                        <table class="data-table">
                            <thead>
                            <tr><th>Reference</th><th>Type</th><th>Level</th><th class="text-right">Amount</th><th class="text-right">Paid</th><th>Status</th><th>Date</th></tr>
                            </thead>
                            <tbody>
                            @foreach ($member->transactions as $transaction)
                                <tr>
                                    <td class="font-mono text-xs">
                                        @if (Route::has('admin.transactions.show'))
                                            <a class="hover:underline" href="{{ route('admin.transactions.show', $transaction) }}">{{ $transaction->reference_no }}</a>
                                        @else
                                            {{ $transaction->reference_no }}
                                        @endif
                                    </td>
                                    <td>{{ $transaction->type->label() }}</td>
                                    <td>{{ $transaction->level->name }}</td>
                                    <td class="text-right">{{ Money::formatDecimal($transaction->amount) }}</td>
                                    <td class="text-right">{{ Money::formatDecimal($transaction->amount_paid) }}</td>
                                    <td><x-badge :status="$transaction->status"/></td>
                                    <td class="whitespace-nowrap">{{ $transaction->created_at->format('M j, Y') }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>

        <div class="space-y-6">
            {{-- Level fee / validity / benefits --}}
            <section class="rounded-2xl bg-charcoal p-6 text-white" aria-labelledby="level-heading">
                <p class="text-xs font-bold uppercase tracking-wider text-sidebar-text">Membership level</p>
                <h2 id="level-heading" class="mt-1 text-2xl font-bold !text-white">{{ $member->level->name }}</h2>
                <p class="text-sm text-sidebar-text">{{ $member->category->name }} category</p>
                <dl class="mt-4 grid grid-cols-2 gap-3">
                    <div><dt class="text-xs text-sidebar-text">Current fee</dt><dd class="text-2xl font-bold text-lime">{{ $member->level->formattedFee() }}</dd></div>
                    <div><dt class="text-xs text-sidebar-text">Validity</dt><dd class="text-2xl font-bold">{{ $member->level->validityLabel() }}</dd></div>
                </dl>
                @if ($member->level->benefitList())
                    <p class="mt-4 text-xs font-bold uppercase tracking-wider text-sidebar-text">Benefits</p>
                    <ul class="mt-2 space-y-1.5 text-sm">
                        @foreach ($member->level->benefitList() as $benefit)
                            <li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-lime"/> {{ $benefit }}</li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Transactions: only the actions the rules currently allow are offered --}}
            <section class="card" aria-labelledby="txn-actions-heading">
                <h2 id="txn-actions-heading" class="card-title">Membership transactions</h2>
                <div class="mt-4 space-y-3">
                    @if ($applicationBlocker === null)
                        <a href="{{ route('admin.transactions.create', ['member' => $member->id, 'type' => 'application']) }}" class="btn-primary w-full">New application</a>
                    @endif
                    @if ($renewalBlocker === null)
                        <a href="{{ route('admin.transactions.create', ['member' => $member->id, 'type' => 'renewal']) }}" class="btn-primary w-full">Renew membership</a>
                    @endif
                    @if ($applicationBlocker !== null && $renewalBlocker !== null)
                        <p class="text-sm text-muted">{{ $member->status === MemberStatus::Pending ? $applicationBlocker : $renewalBlocker }}</p>
                    @endif
                    <a href="{{ route('admin.members.history', $member) }}" class="btn-secondary w-full">View full history</a>
                </div>
            </section>

            @if ($member->hasLocation())
                <x-location-view :latitude="$member->latitude" :longitude="$member->longitude" :consent-at="$member->location_consent_at"/>
                <x-weather-widget id="member-weather" :latitude="$member->latitude" :longitude="$member->longitude" label="At the member's location"/>
            @endif

            {{-- Email verification (required before approving ONLINE applications) --}}
            <section class="card" aria-labelledby="verification-heading">
                <h2 id="verification-heading" class="card-title">Email verification</h2>
                @if ($member->isEmailVerified())
                    <p class="mt-3 text-sm"><x-badge color="green">Verified</x-badge>
                        <span class="text-muted">on {{ $member->email_verified_at->format('M j, Y g:i A') }}</span></p>
                @else
                    <p class="mt-3 text-sm text-muted">
                        @if ($member->source === \App\Enums\MemberSource::Online)
                            <strong class="text-ink">Required:</strong> this online application cannot be approved until the applicant verifies their email.
                        @else
                            Optional for walk-in members (they can be approved without it).
                        @endif
                        @if ($member->verification_sent_at)
                            Last verification email queued {{ $member->verification_sent_at->diffForHumans() }}.
                        @endif
                    </p>
                    <form method="POST" action="{{ route('admin.members.send-verification', $member) }}" class="mt-4">
                        @csrf
                        <button type="submit" class="btn-secondary w-full" data-loading="Queuing…">Send verification email</button>
                    </form>
                @endif

                @if ($deliveries->isNotEmpty())
                    <p class="mt-5 text-xs font-bold uppercase tracking-wider text-muted">Recent emails</p>
                    <ul class="mt-2 space-y-2 text-sm">
                        @foreach ($deliveries as $delivery)
                            <li class="flex flex-col gap-1">
                                <span>{{ $delivery->typeLabel() }} <span class="text-xs text-muted">· {{ $delivery->created_at->diffForHumans() }}</span></span>
                                <x-delivery-status :delivery="$delivery" class="self-start"/>
                            </li>
                        @endforeach
                    </ul>
                    <a href="{{ route('admin.email-deliveries.index', ['q' => $member->email]) }}" class="mt-3 inline-block text-xs font-bold text-muted hover:underline">All emails to this address</a>
                @endif
            </section>

            {{-- Member login account (activation itself arrives with member-account invitations) --}}
            @php $account = $member->account; @endphp
            <section class="card" aria-labelledby="account-heading" data-member-account>
                <h2 id="account-heading" class="card-title">Login account</h2>
                @if (! $account)
                    @php $invStatus = $accountInvitation?->effectiveStatus(); @endphp
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <x-badge color="gray">No login account</x-badge>
                        @if ($accountInvitation)
                            <x-badge :status="$invStatus">Invitation {{ strtolower($invStatus->label()) }}</x-badge>
                        @endif
                    </div>
                    @if ($accountInvitation)
                        <p class="mt-2 text-xs text-muted" data-invitation-status>
                            Sent to {{ $accountInvitation->email }} {{ $accountInvitation->last_sent_at?->diffForHumans() }}
                            ({{ $accountInvitation->inviter?->name ? 'by '.$accountInvitation->inviter->name : 'automatically after email verification' }}).
                            @if ($invStatus === \App\Enums\InvitationStatus::Pending) Expires {{ $accountInvitation->expires_at->format('M j, Y g:i A') }}. @endif
                        </p>
                    @endif

                    <div class="mt-4 space-y-2">
                        @if ($inviteBlocker === null)
                            <form method="POST" action="{{ route('admin.members.account-invitation', $member) }}"
                                  data-confirm="Email {{ $member->email }} a one-time link to activate a member login? It does not approve or pay anything.">
                                @csrf
                                <button type="submit" class="btn-primary w-full" data-loading="Sending…">Send activation invitation</button>
                            </form>
                            <p class="text-xs text-muted">Opening the link proves the member owns {{ $member->email }}; the account is created only when they choose a password.</p>
                        @elseif ($accountInvitation && in_array($invStatus, [\App\Enums\InvitationStatus::Pending, \App\Enums\InvitationStatus::Expired], true) && $accountInvitation->email === strtolower($member->email))
                            <form method="POST" action="{{ route('admin.invitations.resend', $accountInvitation) }}"
                                  data-confirm="Send a NEW activation link to {{ $accountInvitation->email }}? The previous link stops working.">
                                @csrf
                                <button type="submit" class="btn-primary w-full" data-loading="Sending…">Resend activation invitation</button>
                            </form>
                            @if ($invStatus === \App\Enums\InvitationStatus::Pending)
                                <form method="POST" action="{{ route('admin.invitations.revoke', $accountInvitation) }}" data-confirm="Revoke this activation invitation? Its link stops working.">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="btn-secondary w-full">Revoke invitation</button>
                                </form>
                            @endif
                        @else
                            <p class="rounded-xl bg-canvas px-4 py-3 text-sm text-muted" data-invite-blocker>{{ $inviteBlocker }}</p>
                        @endif
                    </div>
                @else
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        @if ($account->is_active)
                            <x-badge color="green">Active</x-badge>
                        @else
                            <x-badge color="gray">Deactivated — cannot sign in</x-badge>
                        @endif
                        @if ($account->is_active && ! $account->canSignIn())
                            <x-badge color="amber">Sign-in blocked while the member record is inactive</x-badge>
                        @endif
                    </div>
                    @if ($emailChangePending)
                        <div class="mt-4 rounded-xl border border-[#f3dfb6] bg-[#fff1d6] px-4 py-3 text-sm text-[#7a5210]" data-email-change-pending>
                            <p><strong>Sign-in email change pending.</strong> The account still signs in with {{ $account->email }} until the member
                                confirms {{ $member->email }} from that inbox while signed in.</p>
                            <form method="POST" action="{{ route('admin.members.account-email-confirmation', $member) }}" class="mt-2">
                                @csrf
                                <button type="submit" class="btn-secondary btn-sm" data-loading="Sending…">Resend confirmation</button>
                            </form>
                        </div>
                    @endif
                    <dl class="mt-4 space-y-3 text-sm">
                        <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">Sign-in email</dt><dd class="mt-0.5 break-words">{{ $account->email }}</dd></div>
                        <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">Activated</dt>
                            <dd class="mt-0.5">{{ ($account->acceptedInvitation?->accepted_at ?? $account->created_at)->format('M j, Y g:i A') }}
                                {{ $account->acceptedInvitation ? '(invitation accepted)' : '' }}</dd></div>
                    </dl>
                    @if ($account->accountEvents->isNotEmpty())
                        <p class="mt-4 text-xs font-bold uppercase tracking-wider text-muted">Recent account changes</p>
                        <ul class="mt-2 space-y-1 text-xs">
                            @foreach ($account->accountEvents as $event)
                                <li>{{ $event->label() }} · {{ $event->actor?->name ?? 'system' }} · {{ $event->created_at->format('M j, Y') }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <div class="mt-4 space-y-2">
                        @if ($account->is_active)
                            <form method="POST" action="{{ route('admin.users.deactivate', $account) }}"
                                  data-confirm="Deactivate {{ $member->fullName() }}'s login account? They are signed out and cannot sign in. Their membership, payments and task history are kept.">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn-secondary w-full">Deactivate login account</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.users.reactivate', $account) }}" data-confirm="Reactivate {{ $member->fullName() }}'s login account?">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn-primary w-full">Reactivate login account</button>
                            </form>
                        @endif
                        <a href="{{ route('admin.users.edit', $account) }}" class="btn-secondary w-full">Account details &amp; history</a>
                    </div>
                @endif
            </section>

            {{-- Lifecycle actions --}}
            <section class="card" aria-labelledby="actions-heading">
                <h2 id="actions-heading" class="card-title">Record actions</h2>
                <div class="mt-4 space-y-3">
                    @if ($member->status === MemberStatus::Inactive)
                        <form method="POST" action="{{ route('admin.members.reactivate', $member) }}" data-confirm="Reactivate {{ $member->fullName() }}?">
                            @csrf @method('PATCH')
                            <button type="submit" class="btn-primary w-full">Reactivate member</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.members.deactivate', $member) }}"
                              data-confirm="Deactivate {{ $member->fullName() }}? Their records and history are kept.">
                            @csrf @method('PATCH')
                            <button type="submit" class="btn-secondary w-full">Deactivate member</button>
                        </form>
                    @endif

                    @if ($member->transactions->isEmpty())
                        <form method="POST" action="{{ route('admin.members.destroy', $member) }}"
                              data-confirm="Delete {{ $member->fullName() }}? This member has no transactions. The record is archived (soft-deleted).">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn-danger w-full">Delete member</button>
                        </form>
                    @else
                        <p class="text-xs text-muted">This member has transaction history, so the record cannot be deleted — deactivate it instead.</p>
                    @endif
                </div>
            </section>
        </div>
    </div>
</x-layouts.app>
