@php use App\Enums\InvitationStatus; $effective = $invitation->effectiveStatus(); @endphp
<x-layouts.app :title="'Invitation · '.$invitation->email">
    <x-page-header eyebrow="Invitation" :title="$invitation->name" :subtitle="$invitation->email">
        <a href="{{ route('admin.invitations.index') }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Invitations</a>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
        <section class="card" aria-labelledby="inv-details">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="inv-details" class="card-title">Invitation details</h2>
                <x-badge :status="$effective" class="!text-xs"/>
            </div>
            <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                @foreach ([
                    'Name' => $invitation->name,
                    'Email' => $invitation->email,
                    'Role' => $invitation->role->label(),
                    'Invited by' => $invitation->inviter?->name,
                    'Created' => $invitation->created_at->format('M j, Y g:i A'),
                    'Last sent' => $invitation->last_sent_at?->format('M j, Y g:i A'),
                    'Times sent' => $invitation->send_count,
                    'Expires' => $invitation->expires_at->format('M j, Y g:i A').($effective === InvitationStatus::Pending ? ' ('.$invitation->expires_at->diffForHumans().')' : ''),
                    'Accepted' => $invitation->accepted_at?->format('M j, Y g:i A'),
                    'Revoked' => $invitation->revoked_at ? $invitation->revoked_at->format('M j, Y g:i A').' by '.($invitation->revoker?->name ?? 'unknown') : null,
                ] as $label => $value)
                    @if ($value !== null)
                        <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">{{ $label }}</dt><dd class="mt-1 break-words">{{ $value }}</dd></div>
                    @endif
                @endforeach
            </dl>
            @if ($invitation->acceptedUser)
                <p class="mt-5 text-sm">Account created: <a class="font-bold hover:underline" href="{{ route('admin.users.edit', $invitation->acceptedUser) }}">{{ $invitation->acceptedUser->name }}</a></p>
            @endif
            <p class="mt-5 text-xs text-muted">For security, the activation link itself is never stored or shown here — only a one-way hash of it.</p>
        </section>

        <section class="card" aria-labelledby="inv-emails">
            <h2 id="inv-emails" class="card-title">Invitation emails</h2>
            <p class="card-subtitle">Each send or resend. “Accepted by SMTP server” means the server took the message — not that it reached the inbox.</p>
            @if ($deliveries->isEmpty())
                <p class="mt-4 text-sm text-muted">No invitation email has been queued for this invitation.</p>
            @else
                <ul class="mt-4 divide-y divide-line text-sm">
                    @foreach ($deliveries as $delivery)
                        <li class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <span>
                                <x-delivery-status :delivery="$delivery"/>
                                @if ($delivery->error_message)
                                    <span class="mt-1 block text-xs text-red-700">{{ $delivery->error_message }}</span>
                                @endif
                            </span>
                            <span class="text-xs text-muted">
                                Queued {{ $delivery->created_at->format('M j, Y g:i A') }} · {{ $delivery->attempts }} attempt(s) · {{ $delivery->mailer }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
        </div>

        <aside class="space-y-6">
            @if (in_array($effective, [InvitationStatus::Pending, InvitationStatus::Expired], true))
                <section class="card" aria-labelledby="resend-heading">
                    <h2 id="resend-heading" class="card-title">Resend</h2>
                    <p class="card-subtitle">Creates a new link valid for 72 hours. The previous link stops working.</p>
                    <form method="POST" action="{{ route('admin.invitations.resend', $invitation) }}" class="mt-4"
                          data-confirm="Resend the invitation to {{ $invitation->email }}? The previous link will stop working.">
                        @csrf
                        <button type="submit" class="btn-primary w-full" data-loading="Resending…">Resend invitation</button>
                    </form>
                </section>
            @endif
            @if ($invitation->status === InvitationStatus::Pending)
                <section class="card" aria-labelledby="revoke-heading">
                    <h2 id="revoke-heading" class="card-title">Revoke</h2>
                    <p class="card-subtitle">The link stops working immediately.</p>
                    <form method="POST" action="{{ route('admin.invitations.revoke', $invitation) }}" class="mt-4"
                          data-confirm="Revoke the invitation for {{ $invitation->email }}?">
                        @csrf @method('PATCH')
                        <button type="submit" class="btn-danger w-full">Revoke invitation</button>
                    </form>
                </section>
            @endif
        </aside>
    </div>
</x-layouts.app>
