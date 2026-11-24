{{--
    Member dashboard. Everything here belongs to the signed-in member: their own membership
    record, their own payments, their own task assignments and emails sent to them.
--}}
<x-layouts.app title="Dashboard">
    <x-page-header :eyebrow="'Member · '.($member->membership_no ?? 'membership number pending')"
                   :title="'Welcome, '.$member->first_name.'.'"
                   subtitle="Your membership, payments and tasks at a glance.">
        <a href="{{ route('my.membership') }}" class="btn-secondary"><x-icon name="user" class="size-4"/> My membership</a>
        <a href="{{ route('my.tasks.index') }}" class="btn-primary"><x-icon name="tasks" class="size-4"/> My tasks</a>
    </x-page-header>

    @if ($emailChangePending)
        <div class="mb-6 rounded-2xl border border-[#f3dfb6] bg-[#fff1d6] px-5 py-4 text-sm text-[#7a5210]" role="status" data-email-change-pending>
            <p><strong>Confirm your new email.</strong> The membership office changed your email to {{ $member->email }}.
                You still sign in with {{ auth()->user()->email }} until you open the confirmation link we sent to the new address.</p>
            <form method="POST" action="{{ route('account.email.resend') }}" class="mt-2">
                @csrf
                <button type="submit" class="btn-secondary btn-sm" data-loading="Sending…">Resend confirmation link</button>
            </form>
        </div>
    @endif

    {{-- Key figures --}}
    <section aria-label="My figures" class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat-card label="Membership" :value="$member->status->label()" icon="user" accent
                     :hint="$validity['current'] ? 'Valid until '.$validity['end']->format('M j, Y') : ($validity['end'] ? 'Ended '.$validity['end']->format('M j, Y') : 'Not yet active')"
                     :href="route('my.membership')"/>
        <x-stat-card label="Days remaining" :value="$validity['days_left'] ?? '—'" icon="calendar"
                     :hint="$validity['current'] ? ($validity['ending_soon'] ? 'Renewal window is open soon' : 'Until your membership ends') : 'No active membership period'"/>
        <x-stat-card label="Balance due" :value="$payments['outstanding']" icon="wallet"
                     :hint="$payments['outstanding_cents'] > 0 ? 'Pay at the membership office' : 'Nothing to pay'" :href="route('my.payments.index')"/>
        <x-stat-card label="Open tasks" :value="$tasks['open']" icon="tasks"
                     :hint="$tasks['overdue'] > 0 ? $tasks['overdue'].' overdue' : $tasks['completion_rate'].'% of your tasks completed'" :href="route('my.tasks.index')"/>
    </section>

    <div class="mt-8 grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            {{-- Membership + what happens next --}}
            <section class="card" aria-labelledby="membership-heading" data-member-status>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="membership-heading" class="card-title">Membership</h2>
                        <p class="card-subtitle">{{ $member->category->name }} · {{ $member->level->name }} level</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-badge :status="$member->status" class="!text-xs"/>
                        @if ($validity['ending_soon'])
                            <x-badge color="amber">Ends in {{ $validity['days_left'] }} {{ str('day')->plural($validity['days_left']) }}</x-badge>
                        @endif
                    </div>
                </div>
                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">Membership no.</dt><dd class="mt-1 font-mono">{{ $member->membership_no ?? 'Assigned on approval' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">Valid from</dt><dd class="mt-1">{{ $validity['start']?->format('M j, Y') ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">Valid until</dt><dd class="mt-1">{{ $validity['end']?->format('M j, Y') ?? '—' }}</dd></div>
                </dl>

                @if ($application)
                    <div @class(['mt-5 rounded-xl px-4 py-3 text-sm', 'bg-[#fff1d6] text-[#7a5210]' => $application['tone'] === 'amber',
                                 'bg-[#e3f5db] text-[#2f5d23]' => $application['tone'] === 'green', 'bg-canvas text-ink' => ! in_array($application['tone'], ['amber', 'green'], true)])
                         data-application-status>
                        <p class="font-bold">{{ $application['headline'] }}</p>
                        <p class="mt-1">{{ $application['detail'] }}</p>
                        <p class="mt-1 font-mono text-xs opacity-80">{{ $application['transaction']->reference_no }}</p>
                    </div>
                @endif
                <p class="mt-4 text-sm" data-renewal-status>
                    <span @class(['font-bold', 'text-green-700' => $renewal['eligible']])>{{ $renewal['eligible'] ? 'Renewal available.' : 'Renewal:' }}</span>
                    <span class="text-muted">{{ $renewal['message'] }}</span>
                </p>
            </section>

            {{-- Tasks --}}
            <section class="card" aria-labelledby="tasks-heading">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="tasks-heading" class="card-title">My current tasks</h2>
                        <p class="card-subtitle">Sorted by due date.</p>
                    </div>
                    <a href="{{ route('my.tasks.index') }}" class="btn-secondary btn-sm">All tasks</a>
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-3 text-center text-sm sm:grid-cols-4" data-task-stats>
                    @foreach (['To do' => $tasks['todo'], 'In progress' => $tasks['in_progress'], 'On hold' => $tasks['on_hold'], 'Completed' => $tasks['completed']] as $label => $count)
                        <div class="rounded-xl bg-canvas px-3 py-2"><dt class="text-xs text-muted">{{ $label }}</dt><dd class="text-xl font-bold">{{ $count }}</dd></div>
                    @endforeach
                </dl>
                @if ($tasks['current']->isEmpty())
                    <x-empty-state icon="check" title="No open tasks" message="When an administrator assigns you a task, it will appear here."/>
                @else
                    <ul class="mt-5 divide-y divide-line">
                        @foreach ($tasks['current'] as $task)
                            <li class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <a href="{{ route('my.tasks.show', $task) }}" class="block truncate font-bold hover:underline">{{ $task->title }}</a>
                                    <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted">
                                        <x-badge :status="$task->priority"/>
                                        <x-badge :status="$task->status"/>
                                        @if ($task->due_date)
                                            <span @class(['font-bold text-red-600' => $task->isOverdue()])>Due {{ $task->due_date->format('M j, Y') }}{{ $task->isOverdue() ? ' · overdue' : '' }}</span>
                                        @endif
                                    </p>
                                </div>
                                <x-progress-bar :value="$task->progress" show-value class="sm:w-48"/>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Recent activity --}}
            <section class="card" aria-labelledby="activity-heading">
                <h2 id="activity-heading" class="card-title">Recent activity</h2>
                <p class="card-subtitle">Your membership, payments, account and tasks.</p>
                @if ($activity->isEmpty())
                    <x-empty-state icon="clock" title="Nothing yet" message="Your applications, payments and task updates will appear here."/>
                @else
                    <ul class="mt-4 divide-y divide-line" data-member-activity>
                        @foreach ($activity as $event)
                            <li class="flex items-start gap-3 py-3">
                                <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-canvas"><x-icon :name="$event['icon']" class="size-4"/></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-bold">{{ $event['label'] }}</p>
                                    @if ($event['detail'] !== '')<p class="truncate text-xs text-muted">{{ $event['detail'] }}</p>@endif
                                </div>
                                <time class="whitespace-nowrap text-xs text-muted" datetime="{{ $event['at']->toIso8601String() }}">{{ $event['at']->format('M j, Y') }}</time>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <div class="space-y-6">
            {{-- Payments --}}
            <section class="card" aria-labelledby="payment-heading">
                <h2 id="payment-heading" class="card-title">Payments</h2>
                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-muted">Total paid</dt><dd class="font-bold">{{ $payments['total_paid'] }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Balance due</dt><dd class="font-bold">{{ $payments['outstanding'] }}</dd></div>
                </dl>
                @if ($payments['last'])
                    <p class="mt-3 text-xs text-muted">Last payment {{ $payments['last']->paid_at->format('M j, Y') }} · {{ $payments['last']->receipt_no }}</p>
                @endif
                <a href="{{ route('my.payments.index') }}" class="btn-secondary mt-4 w-full">Payments &amp; receipts</a>
            </section>

            {{-- Upcoming deadlines --}}
            <section class="card" aria-labelledby="upcoming-heading">
                <h2 id="upcoming-heading" class="card-title">Upcoming deadlines</h2>
                <p class="card-subtitle">Due in the next 7 days.</p>
                @if ($tasks['upcoming']->isEmpty())
                    <x-empty-state icon="calendar" title="No deadlines this week"/>
                @else
                    <ul class="mt-4 space-y-3">
                        @foreach ($tasks['upcoming'] as $task)
                            <li class="flex items-center justify-between gap-3 rounded-xl bg-canvas px-4 py-3">
                                <a href="{{ route('my.tasks.show', $task) }}" class="min-w-0 truncate text-sm font-bold hover:underline">{{ $task->title }}</a>
                                <span class="whitespace-nowrap text-xs font-bold">{{ $task->due_date->isToday() ? 'Today' : $task->due_date->format('M j') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <x-weather-widget id="office-weather" :latitude="config('memberflow.default_latitude')" :longitude="config('memberflow.default_longitude')"
                              :label="config('memberflow.default_location_label').' (office)'"/>

            {{-- Notifications --}}
            <section class="card" aria-labelledby="notifications-heading">
                <h2 id="notifications-heading" class="card-title">Notifications</h2>
                <p class="card-subtitle">Emails MemberFlow sent you.</p>
                @if ($notifications->isEmpty())
                    <x-empty-state icon="mail" title="No notifications yet"/>
                @else
                    <ul class="mt-4 space-y-3" data-member-notifications>
                        @foreach ($notifications as $note)
                            <li class="rounded-xl bg-canvas px-4 py-3 text-sm">
                                <p class="font-bold">{{ $note['label'] }}</p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-muted">
                                    <x-badge :color="$note['tone']">{{ $note['state'] }}</x-badge>
                                    <time datetime="{{ $note['at']->toIso8601String() }}">{{ $note['at']->format('M j, Y') }}</time>
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</x-layouts.app>
