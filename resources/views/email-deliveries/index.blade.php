@php use App\Enums\EmailDeliveryStatus; use App\Models\EmailDelivery; @endphp
<x-layouts.app title="Email log">
    <x-page-header eyebrow="Team" title="Email log"
                   subtitle="Every email MemberFlow tried to send, with an honest delivery status. Links and tokens are never stored here."/>

    {{-- Current mail configuration (no credentials are shown) --}}
    <section class="card mb-6" aria-labelledby="config-heading">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h2 id="config-heading" class="card-title">Mail configuration</h2>
                <dl class="mt-3 grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                    <div><dt class="inline text-muted">Mailer:</dt> <dd class="inline font-mono font-bold">{{ $mailer }}</dd></div>
                    <div><dt class="inline text-muted">From:</dt> <dd class="inline font-mono">{{ $fromAddress }}</dd></div>
                    @if ($mailer === 'smtp')
                        <div><dt class="inline text-muted">SMTP server:</dt> <dd class="inline font-mono">{{ $smtpHost }}:{{ $smtpPort }}</dd></div>
                    @endif
                    <div><dt class="inline text-muted">Queue:</dt> <dd class="inline">{{ $pendingJobs }} waiting job(s), {{ $failedJobs }} failed job(s)</dd></div>
                </dl>
            </div>
            <form method="POST" action="{{ route('admin.email-deliveries.test') }}" class="shrink-0">
                @csrf
                <button type="submit" class="btn-dark" data-loading="Queuing…">
                    <x-icon name="mail" class="size-4"/> Send test email to me
                </button>
                <p class="form-hint">Sends only to your own address ({{ auth()->user()->email }}).</p>
            </form>
        </div>

        @if ($missingCredentials)
            <div class="alert-error mt-4" role="alert">
                <x-icon name="alert" class="mt-0.5 size-4 shrink-0"/>
                <p>MAIL_MAILER is <strong>smtp</strong> but MAIL_USERNAME or MAIL_PASSWORD is empty in <code>.env</code>. Every email will fail until they are set.</p>
            </div>
        @elseif ($logMode)
            <div class="alert-warning mt-4" role="status">
                <x-icon name="alert" class="mt-0.5 size-4 shrink-0"/>
                <p>Local log mode (MAIL_MAILER={{ $mailer }}): emails are written to <code>storage/logs/mail.log</code> with secure links removed, and are <strong>NOT delivered</strong> to anyone.</p>
            </div>
        @endif

        @if ($staleCount > 0 || $pendingJobs > 0)
            <div class="alert-info mt-4" role="status">
                <x-icon name="clock" class="mt-0.5 size-4 shrink-0"/>
                <p>
                    Emails are sent by the queue worker. Keep <code>php artisan queue:work</code> running in a separate terminal.
                    @if ($staleCount > 0)
                        <strong>{{ $staleCount }} email(s) have been queued for more than {{ EmailDelivery::STALE_AFTER_MINUTES }} minutes</strong> — the worker is probably not running.
                    @endif
                </p>
            </div>
        @endif
    </section>

    {{-- Status overview --}}
    <section aria-label="Delivery totals" class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
        @foreach (EmailDeliveryStatus::cases() as $case)
            <a href="{{ route('admin.email-deliveries.index', ['status' => $case->value]) }}"
               @class(['rounded-xl border bg-white px-4 py-3 hover:bg-field', 'border-ink' => $filters['status'] === $case, 'border-line' => $filters['status'] !== $case])>
                <span class="block text-xs text-muted">{{ $case->shortLabel() }}</span>
                <span @class(['text-xl font-bold', 'text-red-700' => $case === EmailDeliveryStatus::Failed && ($counts[$case->value] ?? 0) > 0])>{{ $counts[$case->value] ?? 0 }}</span>
            </a>
        @endforeach
    </section>

    <section class="card">
        <form method="GET" class="flex flex-col gap-3 lg:flex-row lg:items-end" role="search">
            <x-form.input name="q" label="Recipient" :value="$filters['q']" placeholder="Search email address" class="flex-1"/>
            <x-form.select name="status" label="Status" placeholder="All statuses" :value="$filters['status']"
                           :options="collect(EmailDeliveryStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->shortLabel()])->all()"/>
            <x-form.select name="type" label="Type" placeholder="All types" :value="$filters['type']" :options="EmailDelivery::TYPE_LABELS"/>
            <label class="flex items-center gap-2 pb-3 text-sm">
                <input type="checkbox" name="stale" value="1" class="size-4 accent-[#5d7f24]" @checked($filters['stale'])> Stale queue only
            </label>
            <div class="flex gap-2">
                <button type="submit" class="btn-dark">Filter</button>
                @if ($hasFilters)
                    <a href="{{ route('admin.email-deliveries.index') }}" class="btn-secondary">Clear</a>
                @endif
            </div>
        </form>

        @if ($deliveries->isEmpty())
            <x-empty-state icon="mail" :title="$hasFilters ? 'No emails match these filters' : 'No emails yet'"
                           message="Invitations, applicant verifications, membership and task notifications will be listed here."/>
        @else
            <div class="mt-6 overflow-x-auto">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>Type</th><th>Recipient</th><th>Related</th><th>Mailer</th><th>Status</th>
                        <th class="text-right">Attempts</th><th>Queued</th><th>Sent / failed</th><th><span class="sr-only">Actions</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($deliveries as $delivery)
                        @php $related = $delivery->related; @endphp
                        <tr>
                            <td class="whitespace-nowrap">{{ $delivery->typeLabel() }}</td>
                            <td class="break-all font-mono text-xs">{{ $delivery->recipient_email }}</td>
                            <td class="whitespace-nowrap text-xs">
                                @switch($delivery->related_type)
                                    @case('member')
                                        @if ($related && ! $related->trashed())
                                            <a class="hover:underline" href="{{ route('admin.members.show', $related) }}">{{ $related->membership_no }}</a>
                                        @else Member #{{ $delivery->related_id }} @endif
                                        @break
                                    @case('transaction')
                                        @if ($related)
                                            <a class="font-mono hover:underline" href="{{ route('admin.transactions.show', $related) }}">{{ $related->reference_no }}</a>
                                        @else Transaction #{{ $delivery->related_id }} @endif
                                        @break
                                    @case('task')
                                        @if ($related)
                                            <a class="hover:underline" href="{{ route('admin.tasks.show', $related->id) }}">{{ $related->code() }}</a>
                                        @else Task #{{ $delivery->related_id }} @endif
                                        @break
                                    @case('invitation')
                                        <a class="hover:underline" href="{{ route('admin.invitations.show', $delivery->related_id) }}">Invitation #{{ $delivery->related_id }}</a>
                                        @break
                                    @case('user')
                                        User #{{ $delivery->related_id }}
                                        @break
                                    @default
                                        —
                                @endswitch
                            </td>
                            <td class="font-mono text-xs">{{ $delivery->mailer }}</td>
                            <td>
                                <x-delivery-status :delivery="$delivery"/>
                                @if ($delivery->superseded_by_id)
                                    <span class="mt-1 block text-xs text-muted">Retried as #{{ $delivery->superseded_by_id }}</span>
                                @endif
                                @if ($delivery->error_message)
                                    <details class="mt-1 max-w-xs text-xs text-red-700">
                                        <summary class="cursor-pointer">Error details</summary>
                                        <p class="mt-1 break-words">{{ $delivery->error_message }}</p>
                                    </details>
                                @endif
                            </td>
                            <td class="text-right">{{ $delivery->attempts }}</td>
                            <td class="whitespace-nowrap text-xs">{{ $delivery->queued_at?->format('M j, Y g:i A') ?? '—' }}</td>
                            <td class="whitespace-nowrap text-xs">
                                {{ ($delivery->sent_at ?? $delivery->failed_at)?->format('M j, Y g:i A') ?? '—' }}
                            </td>
                            <td>
                                @if ($delivery->status === EmailDeliveryStatus::Failed && ! $delivery->superseded_by_id)
                                    <form method="POST" action="{{ route('admin.email-deliveries.retry', $delivery) }}"
                                          data-confirm="Queue this email again? A new log entry is created and this one is marked as retried.">
                                        @csrf
                                        <button type="submit" class="btn-secondary btn-sm">Retry</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-6">{{ $deliveries->links() }}</div>
        @endif
    </section>

    <section class="mt-6 text-xs leading-relaxed text-muted" aria-label="Status meanings">
        <p class="font-bold">What the statuses mean</p>
        <ul class="mt-1 space-y-1">
            @foreach (EmailDeliveryStatus::cases() as $case)
                <li><strong>{{ $case->label() }}:</strong> {{ $case->description() }}</li>
            @endforeach
        </ul>
    </section>
</x-layouts.app>
