@php use App\Support\Money; @endphp
<x-layouts.app title="Dashboard">
    <x-page-header eyebrow="Administrator" title="Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 18 ? 'afternoon' : 'evening') }}, {{ strtok(auth()->user()->name, ' ') }}."
                   subtitle="A live overview of members, payments and your team's work.">
        @if (Route::has('admin.reports.index'))
            <a href="{{ route('admin.reports.index') }}" class="btn-secondary"><x-icon name="chart" class="size-4"/> Reports</a>
        @endif
    </x-page-header>

    @if ($email['failed'] > 0 || $email['stale'] > 0)
        <div class="alert-error mb-6" role="alert" data-email-alert>
            <x-icon name="mail" class="mt-0.5 size-4 shrink-0"/>
            <p class="flex-1">
                @if ($email['failed'] > 0)
                    <strong>{{ $email['failed'] }} email {{ str('delivery')->plural($email['failed']) }} failed.</strong>
                @endif
                @if ($email['stale'] > 0)
                    <strong>{{ $email['stale'] }} {{ str('email')->plural($email['stale']) }} queued for over {{ \App\Models\EmailDelivery::STALE_AFTER_MINUTES }} minutes</strong> — is <code>php artisan queue:work</code> running?
                @endif
                <a href="{{ route('admin.email-deliveries.index', $email['failed'] > 0 ? ['status' => 'failed'] : ['stale' => 1]) }}" class="font-bold underline">Open the Email log</a>
            </p>
        </div>
    @endif

    @if ($email['unverified_online'] > 0)
        <div class="alert-info mb-6" role="status">
            <x-icon name="mail" class="mt-0.5 size-4 shrink-0"/>
            <p class="flex-1">{{ $email['unverified_online'] }} online {{ str('application')->plural($email['unverified_online']) }} awaiting email verification.
                <a href="{{ route('admin.members.index', ['source' => 'online', 'status' => 'pending']) }}" class="font-bold underline">View applicants</a></p>
        </div>
    @endif

    {{-- Membership --}}
    <h2 class="sr-only">Key figures</h2>
    <section aria-label="Membership figures" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Total members" :value="number_format($stats['total_members'])" icon="users"
                     :hint="number_format($stats['pending_members']).' pending · '.number_format($stats['inactive_members']).' inactive'" :href="route('admin.members.index')"/>
        <x-stat-card label="Active members" :value="number_format($stats['active_members'])" icon="check"
                     :hint="$stats['expiring_soon'].' expiring within 30 days'" :href="route('admin.members.index', ['status' => 'active'])"/>
        <x-stat-card label="Pending applications" :value="number_format($stats['pending_applications'])" icon="user-plus"
                     :hint="$stats['pending_renewals'].' renewals also open'" :href="route('admin.transactions.index', ['type' => 'application'])"/>
        <x-stat-card label="Expired memberships" :value="number_format($stats['expired_members'])" icon="clock"
                     hint="Members whose validity has ended" :href="route('admin.members.index', ['status' => 'expired'])"/>
    </section>

    {{-- Money and work --}}
    <section aria-label="Payment and transaction figures" class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Collected (valid payments)" :value="Money::format($stats['collected_total'])" icon="wallet" accent
                     :hint="Money::format($stats['collected_month']).' this month · voided excluded'" :href="route('admin.payments.index', ['state' => 'valid'])"/>
        <x-stat-card label="Outstanding balances" :value="Money::format($stats['outstanding_total'])" icon="receipt"
                     :hint="$stats['outstanding_count'].' open '.str('transaction')->plural($stats['outstanding_count']).' · cancelled excluded'"
                     :href="Route::has('admin.reports.index') ? route('admin.reports.index').'#outstanding' : null"/>
        <x-stat-card label="Transactions" :value="number_format($stats['pending_transactions']).' open'" icon="layers"
                     :hint="$stats['awaiting_payment'].' awaiting payment · '.$stats['awaiting_approval'].' awaiting approval · '.$stats['approved_transactions'].' approved'"
                     :href="route('admin.transactions.index', ['status' => 'paid'])"/>
        <x-stat-card label="Open tasks" :value="number_format($stats['open_tasks'])" icon="tasks"
                     :hint="$stats['overdue_tasks'].' overdue'" :href="route('admin.tasks.index')"/>
    </section>

    <div class="mt-8 grid gap-6 xl:grid-cols-3">
        <section class="card xl:col-span-2" aria-labelledby="recent-heading">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <h2 id="recent-heading" class="card-title">Recent transactions</h2>
                    <p class="card-subtitle">The latest membership applications and renewals.</p>
                </div>
                <a href="{{ route('admin.transactions.index') }}" class="btn-secondary btn-sm">All transactions</a>
            </div>

            @if ($recentTransactions->isEmpty())
                <x-empty-state title="No transactions yet"
                               message="Applications and renewals will appear here as soon as members are registered."/>
            @else
                <div class="mt-5 overflow-x-auto">
                    <table class="data-table">
                        <thead>
                        <tr><th scope="col">Reference</th><th scope="col">Member</th><th scope="col">Type</th><th scope="col" class="text-right">Amount</th><th scope="col">Status</th></tr>
                        </thead>
                        <tbody>
                        @foreach ($recentTransactions as $transaction)
                            <tr>
                                <td class="font-mono text-xs"><a class="hover:underline" href="{{ route('admin.transactions.show', $transaction) }}">{{ $transaction->reference_no }}</a></td>
                                <td>{{ $transaction->member?->fullName() }}</td>
                                <td>{{ $transaction->type->label() }}</td>
                                <td class="text-right">{{ Money::formatDecimal($transaction->amount) }}</td>
                                <td><x-badge :status="$transaction->status"/></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="team-heading">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <h2 id="team-heading" class="card-title">Team tasks</h2>
                    <p class="card-subtitle">{{ $taskStats['total'] }} active {{ str('task')->plural($taskStats['total']) }} (excluding archived).</p>
                </div>
                <a href="{{ route('admin.tasks.index') }}" class="btn-secondary btn-sm">All tasks</a>
            </div>
            <dl class="mt-5 grid grid-cols-2 gap-3">
                @foreach ([
                    ['To Do', $taskStats['todo'], ['status' => 'todo']],
                    ['In Progress', $taskStats['in_progress'], ['status' => 'in_progress']],
                    ['On Hold', $taskStats['on_hold'], ['status' => 'on_hold']],
                    ['Completed', $taskStats['completed'], ['status' => 'completed']],
                ] as [$label, $count, $query])
                    <div class="relative rounded-xl bg-canvas px-4 py-3 hover:bg-field">
                        <dt class="text-xs text-muted"><a href="{{ route('admin.tasks.index', $query) }}" class="after:absolute after:inset-0">{{ $label }}</a></dt>
                        <dd class="text-xl font-bold">{{ $count }}</dd>
                    </div>
                @endforeach
                <div class="relative col-span-2 rounded-xl bg-canvas px-4 py-3 hover:bg-field">
                    <dt class="text-xs text-muted"><a href="{{ route('admin.tasks.index', ['due' => 'overdue']) }}" class="after:absolute after:inset-0">Overdue</a></dt>
                    <dd @class(['text-xl font-bold', 'text-red-700' => $taskStats['overdue'] > 0])>{{ $taskStats['overdue'] }}@if ($taskStats['overdue'] > 0) <span class="text-xs font-bold">· needs attention</span>@endif</dd>
                </div>
            </dl>
        </section>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <section class="card" aria-labelledby="payments-heading">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <h2 id="payments-heading" class="card-title">Recent payments</h2>
                    <p class="card-subtitle">Voided payments are shown but not counted as collected.</p>
                </div>
                <a href="{{ route('admin.payments.index') }}" class="btn-secondary btn-sm">All payments</a>
            </div>
            @if ($recentPayments->isEmpty())
                <x-empty-state icon="wallet" title="No payments yet" message="Recorded payments will appear here."/>
            @else
                <ul class="mt-4 divide-y divide-line">
                    @foreach ($recentPayments as $payment)
                        <li class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0 text-sm">
                                <a href="{{ route('admin.payments.show', $payment) }}" class="font-mono text-xs hover:underline">{{ $payment->receipt_no }}</a>
                                <span class="block truncate">{{ $payment->transaction?->member?->fullName() }} · {{ $payment->method->label() }}</span>
                            </div>
                            <div class="flex items-center gap-2 sm:text-right">
                                @if ($payment->voided_at)
                                    <x-badge color="red">Voided</x-badge>
                                    <span class="text-sm text-muted line-through">{{ Money::formatDecimal($payment->amount) }}</span>
                                @else
                                    <span class="text-sm font-bold">{{ Money::formatDecimal($payment->amount) }}</span>
                                @endif
                                <time class="whitespace-nowrap text-xs text-muted" datetime="{{ $payment->paid_at->toIso8601String() }}">{{ $payment->paid_at->format('M j') }}</time>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="card" aria-labelledby="members-heading">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <h2 id="members-heading" class="card-title">Recent membership activity</h2>
                    <p class="card-subtitle">Newest member records (walk-in and online).</p>
                </div>
                <a href="{{ route('admin.members.index') }}" class="btn-secondary btn-sm">Directory</a>
            </div>
            @if ($recentMembers->isEmpty())
                <x-empty-state icon="users" title="No members yet" message="Registered members and online applicants will appear here."/>
            @else
                <ul class="mt-4 divide-y divide-line">
                    @foreach ($recentMembers as $member)
                        <li class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0 text-sm">
                                <a href="{{ route('admin.members.show', $member) }}" class="font-bold hover:underline">{{ $member->fullName() }}</a>
                                <span class="block text-xs text-muted">{{ $member->membership_no }} · {{ $member->level->name }}</span>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-badge :status="$member->status"/>
                                <x-badge :status="$member->source"/>
                                <time class="whitespace-nowrap text-xs text-muted" datetime="{{ $member->created_at->toIso8601String() }}">{{ $member->created_at->diffForHumans() }}</time>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <section class="card xl:col-span-2" aria-labelledby="task-activity-heading">
            <h2 id="task-activity-heading" class="card-title">Recent task activity</h2>
            @if ($recentActivity->isEmpty())
                <x-empty-state icon="clock" title="No task activity yet" message="Task creation, assignments and progress updates will appear here."/>
            @else
                <ul class="mt-4 divide-y divide-line">
                    @foreach ($recentActivity as $activity)
                        <li class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm">
                                <x-badge :color="$activity->color()">{{ $activity->label() }}</x-badge>
                                <span class="font-bold">{{ $activity->user?->name }}</span> ·
                                <a class="hover:underline" href="{{ route('admin.tasks.show', $activity->task) }}">{{ $activity->task->title }}</a>
                                @if ($activity->new_progress !== null && $activity->old_progress !== $activity->new_progress)
                                    <span class="text-muted">({{ $activity->old_progress }}% → {{ $activity->new_progress }}%)</span>
                                @endif
                            </p>
                            <time class="whitespace-nowrap text-xs text-muted" datetime="{{ $activity->created_at->toIso8601String() }}">{{ $activity->created_at->diffForHumans() }}</time>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <div class="space-y-6">
            <section class="card" aria-labelledby="email-heading">
                <h2 id="email-heading" class="card-title">Email delivery</h2>
                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-xl bg-canvas px-4 py-3">
                        <dt class="text-xs text-muted">Queued</dt>
                        <dd class="text-xl font-bold">{{ $email['queued'] }}</dd>
                    </div>
                    <div class="rounded-xl bg-canvas px-4 py-3">
                        <dt class="text-xs text-muted">Failed (not retried)</dt>
                        <dd @class(['text-xl font-bold', 'text-red-700' => $email['failed'] > 0])>{{ $email['failed'] }}</dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs text-muted">{{ $email['jobs'] }} {{ str('job')->plural($email['jobs']) }} waiting for the queue worker.</p>
                <a href="{{ route('admin.email-deliveries.index') }}" class="btn-secondary btn-sm mt-3">Email log</a>
            </section>

            <x-weather-widget id="office-weather" :latitude="config('memberflow.default_latitude')" :longitude="config('memberflow.default_longitude')"
                              :label="config('memberflow.default_location_label').' (office)'"/>
        </div>
    </div>
</x-layouts.app>
