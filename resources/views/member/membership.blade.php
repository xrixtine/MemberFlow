@php use App\Support\Money; @endphp
{{-- The signed-in member's OWN membership record (no ids in the URL). Read-only. --}}
<x-layouts.app title="My membership">
    <x-page-header :eyebrow="'Membership · '.($member->membership_no ?? 'number assigned on approval')" :title="$member->fullName()"
                   subtitle="Your membership status, level, renewal and application history.">
        <a href="{{ route('my.membership.print') }}" class="btn-secondary" target="_blank" rel="noopener"><x-icon name="receipt" class="size-4"/> Print my record</a>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <section class="card" aria-labelledby="status-heading">
                <h2 id="status-heading" class="card-title">Status &amp; validity</h2>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <x-badge :status="$member->status" class="!text-xs"/>
                    @if ($validity['ending_soon'])
                        <x-badge color="amber">Ends in {{ $validity['days_left'] }} {{ str('day')->plural($validity['days_left']) }}</x-badge>
                    @endif
                    @if ($member->isEmailVerified())
                        <x-badge color="green">Email verified</x-badge>
                    @else
                        <x-badge color="amber">Email not verified</x-badge>
                    @endif
                </div>
                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">Start date</dt><dd class="mt-1">{{ $validity['start']?->format('F j, Y') ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">Expiry date</dt><dd class="mt-1">{{ $validity['end']?->format('F j, Y') ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">Days remaining</dt><dd class="mt-1">{{ $validity['days_left'] ?? '—' }}</dd></div>
                </dl>
                @unless ($validity['start'])
                    <p class="mt-4 text-sm text-muted">Your membership starts when a fully paid application is approved by the membership office.</p>
                @endunless
            </section>

            @if ($application)
                <section class="card" aria-labelledby="application-heading" data-application-status>
                    <h2 id="application-heading" class="card-title">Application &amp; renewal status</h2>
                    <p class="mt-3 font-bold">{{ $application['headline'] }}</p>
                    <p class="mt-1 text-sm text-muted">{{ $application['detail'] }}</p>
                    @php $t = $application['transaction']; @endphp
                    <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-4">
                        <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">Reference</dt><dd class="mt-1 font-mono text-xs">{{ $t->reference_no }}</dd></div>
                        <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">Amount</dt><dd class="mt-1">{{ Money::formatDecimal($t->amount) }}</dd></div>
                        <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">Paid</dt><dd class="mt-1">{{ Money::formatDecimal($t->amount_paid) }}</dd></div>
                        <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">Status</dt><dd class="mt-1"><x-badge :status="$t->status"/></dd></div>
                    </dl>
                </section>
            @endif

            <section class="card" aria-labelledby="details-heading">
                <h2 id="details-heading" class="card-title">My details</h2>
                <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    @foreach ([
                        'Membership no.' => $member->membership_no,
                        'Email' => $member->email,
                        'Phone' => $member->phone,
                        'Birthdate' => $member->birthdate?->format('F j, Y'),
                        'Address' => $member->address,
                        'Category' => $member->category->name,
                        'Level' => $member->level->name,
                        'Member since' => $member->created_at->format('F j, Y'),
                    ] as $label => $value)
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wider text-muted">{{ $label }}</dt>
                            <dd class="mt-1 break-words">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
                <p class="mt-5 text-xs text-muted">Your details are kept by the membership office. To correct them, please contact the office.</p>
            </section>

            <section class="card" aria-labelledby="history-heading">
                <h2 id="history-heading" class="card-title">Application &amp; renewal history</h2>
                <p class="card-subtitle">Amounts are the fees recorded at the time of each transaction.</p>
                @if ($transactions->isEmpty())
                    <x-empty-state icon="receipt" title="No transactions yet"/>
                @else
                    <div class="mt-5 overflow-x-auto">
                        <table class="data-table">
                            <thead><tr><th>Reference</th><th>Type &amp; level</th><th class="text-right">Amount</th><th class="text-right">Paid</th><th>Status</th><th>Period</th></tr></thead>
                            <tbody>
                            @foreach ($transactions as $transaction)
                                <tr>
                                    <td class="whitespace-nowrap font-mono text-xs">{{ $transaction->reference_no }}</td>
                                    <td>{{ $transaction->type->label() }}<span class="block text-xs text-muted">{{ $transaction->level->name }}</span></td>
                                    <td class="text-right">{{ Money::formatDecimal($transaction->amount) }}</td>
                                    <td class="text-right">{{ Money::formatDecimal($transaction->amount_paid) }}</td>
                                    <td><x-badge :status="$transaction->status"/></td>
                                    <td class="min-w-28 text-xs">
                                        {{ $transaction->period_start ? $transaction->period_start->format('M j, Y').' – '.$transaction->period_end->format('M j, Y') : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>

        <aside class="space-y-6">
            <section class="card" aria-labelledby="renewal-heading" data-renewal-status>
                <h2 id="renewal-heading" class="card-title">Renewal</h2>
                <p class="mt-3">
                    @if ($renewal['eligible'])
                        <x-badge color="green">Available now</x-badge>
                    @else
                        <x-badge color="gray">Not available yet</x-badge>
                    @endif
                </p>
                <p class="mt-3 text-sm text-muted">{{ $renewal['message'] }}</p>
                <p class="mt-3 text-xs text-muted">Renewals are set up by the membership office; you cannot renew or pay online.</p>
            </section>

            <section class="rounded-2xl bg-charcoal p-6 text-white" aria-labelledby="level-heading">
                <p class="text-xs font-bold uppercase tracking-wider text-sidebar-text">Membership level</p>
                <h2 id="level-heading" class="mt-1 text-2xl font-bold !text-white">{{ $member->level->name }}</h2>
                <p class="text-sm text-sidebar-text">{{ $member->category->name }} category · {{ $member->level->validityLabel() }}</p>
                @if ($member->level->benefitList())
                    <p class="mt-4 text-xs font-bold uppercase tracking-wider text-sidebar-text">Benefits</p>
                    <ul class="mt-2 space-y-1.5 text-sm">
                        @foreach ($member->level->benefitList() as $benefit)
                            <li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-lime"/> {{ $benefit }}</li>
                        @endforeach
                    </ul>
                @endif
            </section>
            <a href="{{ route('my.payments.index') }}" class="btn-secondary w-full">Payments &amp; receipts</a>

            @if ($showLocation)
                <x-location-view audience="member" :latitude="$member->latitude" :longitude="$member->longitude" :consent-at="$member->location_consent_at"/>
                <x-weather-widget id="my-location-weather" :latitude="$member->latitude" :longitude="$member->longitude" label="At your location on file"/>
            @endif
        </aside>
    </div>
</x-layouts.app>
