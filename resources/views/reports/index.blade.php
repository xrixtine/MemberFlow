@php
    use App\Enums\MemberStatus;
    use App\Enums\TaskStatus;
    use App\Enums\TransactionStatus;
    use App\Support\Money;

    $memberTotal = array_sum($memberStatus);
    $pct = fn (int $part, int $whole) => $whole > 0 ? (int) round($part / $whole * 100) : 0;
    $period = $from->format('M j, Y').' – '.$to->format('M j, Y');
    $sections = ['membership' => 'Membership', 'transactions' => 'Transactions', 'payments' => 'Payments',
                 'outstanding' => 'Outstanding balances', 'tasks' => 'Tasks', 'workload' => 'Task workload'];
@endphp
<x-layouts.app title="Reports">
    <x-page-header eyebrow="Overview" title="Reports"
                   :subtitle="'Figures come straight from the database. Period: '.$period.'. Generated '.$generatedAt->format('M j, Y g:i A').'.'">
        <button type="button" class="btn-dark no-print" data-print><x-icon name="receipt" class="size-4"/> Print</button>
    </x-page-header>

    {{-- Period filter --}}
    <section class="card no-print" aria-labelledby="filter-heading">
        <h2 id="filter-heading" class="sr-only">Report period</h2>
        <form method="GET" class="flex flex-col gap-4 md:flex-row md:items-end">
            <x-form.input name="from" type="date" label="From" :value="$from->format('Y-m-d')" class="md:w-48"/>
            <x-form.input name="to" type="date" label="To" :value="$to->format('Y-m-d')" class="md:w-48"/>
            <button type="submit" class="btn-dark">Apply period</button>
        </form>
        <div class="mt-4 flex flex-wrap gap-2" aria-label="Quick periods">
            @foreach ($presets as $label => [$pFrom, $pTo])
                @php $current = $pFrom->isSameDay($from) && $pTo->isSameDay($to); @endphp
                <a href="{{ route('admin.reports.index', ['from' => $pFrom->format('Y-m-d'), 'to' => $pTo->format('Y-m-d')]) }}"
                   @class(['btn-sm', 'btn-primary' => $current, 'btn-secondary' => ! $current]) @if ($current) aria-current="true" @endif>{{ $label }}</a>
            @endforeach
        </div>
        <nav class="mt-5 border-t border-line pt-4 text-sm" aria-label="Report sections">
            <ul class="flex flex-wrap gap-x-4 gap-y-2">
                @foreach ($sections as $id => $label)
                    <li><a href="#{{ $id }}" class="font-bold text-muted hover:text-ink hover:underline">{{ $label }}</a></li>
                @endforeach
            </ul>
        </nav>
        <p class="mt-4 text-xs text-muted">
            <strong>Period</strong> sections (applications &amp; renewals opened, payments, tasks completed) use the dates above.
            <strong>Snapshot</strong> sections (member status, transaction pipeline, outstanding balances, workload) show the situation right now.
        </p>
    </section>

    {{-- ===================== Membership ===================== --}}
    <section id="membership" class="card mt-6 scroll-mt-24" aria-labelledby="membership-heading">
        <h2 id="membership-heading" class="card-title">Membership <span class="text-sm font-normal text-muted">· snapshot</span></h2>
        <p class="card-subtitle">Each member is counted once, by current status. Deleted records are excluded.</p>

        <dl class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-5">
            <div class="rounded-xl bg-canvas px-4 py-3"><dt class="text-xs text-muted">All members</dt><dd class="text-2xl font-bold">{{ number_format($memberTotal) }}</dd></div>
            @foreach (MemberStatus::cases() as $status)
                <div class="rounded-xl bg-canvas px-4 py-3">
                    <dt class="text-xs text-muted">{{ $status->label() }}</dt>
                    <dd class="text-2xl font-bold">{{ number_format($memberStatus[$status->value]) }}</dd>
                    <dd class="text-xs text-muted">{{ $pct($memberStatus[$status->value], $memberTotal) }}%</dd>
                </div>
            @endforeach
        </dl>
        <p class="mt-3 text-sm text-muted">Source: {{ $memberSources['walk_in'] }} walk-in · {{ $memberSources['online'] }} online.</p>

        @foreach (['category' => $byCategory, 'level' => $byLevel] as $dimension => $rows)
            <h3 class="mt-6 font-bold">By {{ $dimension }}</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="data-table">
                    <caption class="sr-only">Members by {{ $dimension }} and status</caption>
                    <thead>
                    <tr>
                        <th scope="col">{{ ucfirst($dimension) }}</th>
                        @foreach (MemberStatus::cases() as $status)<th scope="col" class="text-right">{{ $status->label() }}</th>@endforeach
                        <th scope="col" class="text-right">Total</th>
                        <th scope="col" class="w-40"><span class="sr-only">Share of all members</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <th scope="row" class="font-bold">{{ $row['name'] }} @unless ($row['active'])<x-badge color="gray">Inactive</x-badge>@endunless</th>
                            @foreach (MemberStatus::cases() as $status)<td class="text-right">{{ $row['counts'][$status->value] }}</td>@endforeach
                            <td class="text-right font-bold">{{ $row['total'] }}</td>
                            <td><div class="meter" role="img" aria-label="{{ $pct($row['total'], $memberTotal) }}% of members"><span style="width: {{ $pct($row['total'], $memberTotal) }}%"></span></div></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted">No {{ $dimension }} records.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    </section>

    {{-- ===================== Transactions ===================== --}}
    <section id="transactions" class="card mt-6 scroll-mt-24" aria-labelledby="transactions-heading">
        <h2 id="transactions-heading" class="card-title">Applications and renewals</h2>
        <p class="card-subtitle">Opened {{ $period }}, by current status. “Billed” excludes cancelled transactions.</p>

        <div class="mt-5 overflow-x-auto">
            <table class="data-table">
                <caption class="sr-only">Transactions opened in the period</caption>
                <thead>
                <tr>
                    <th scope="col">Type</th>
                    @foreach (TransactionStatus::cases() as $status)<th scope="col" class="text-right">{{ $status->label() }}</th>@endforeach
                    <th scope="col" class="text-right">Total</th><th scope="col" class="text-right">Billed</th><th scope="col" class="text-right">Paid</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($opened as $row)
                    <tr>
                        <th scope="row" class="font-bold">{{ $row['label'] }}s</th>
                        @foreach (TransactionStatus::cases() as $status)<td class="text-right">{{ $row['counts'][$status->value] }}</td>@endforeach
                        <td class="text-right font-bold">{{ $row['total'] }}</td>
                        <td class="text-right">{{ Money::format($row['billed']) }}</td>
                        <td class="text-right">{{ Money::format($row['paid']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <h3 class="mt-6 font-bold">Transaction pipeline <span class="text-sm font-normal text-muted">· snapshot, all transactions</span></h3>
        <dl class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-5">
            @foreach (TransactionStatus::cases() as $status)
                <div class="rounded-xl bg-canvas px-4 py-3">
                    <dt class="flex items-center gap-2 text-xs text-muted"><x-badge :status="$status"/></dt>
                    <dd class="mt-1 text-2xl font-bold">{{ number_format($transactionStatus[$status->value]) }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    {{-- ===================== Payments ===================== --}}
    <section id="payments" class="card mt-6 scroll-mt-24" aria-labelledby="payments-heading">
        <h2 id="payments-heading" class="card-title">Payment collections</h2>
        <p class="card-subtitle">Valid payments received {{ $period }}. Voided payments are excluded from the totals and listed separately.</p>

        <dl class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-3">
            <div class="rounded-xl bg-charcoal px-4 py-3 text-white"><dt class="text-xs text-sidebar-text">Collected</dt><dd class="text-2xl font-bold text-lime">{{ Money::format($payments['total']) }}</dd><dd class="text-xs text-sidebar-text">{{ $payments['count'] }} {{ str('payment')->plural($payments['count']) }}</dd></div>
            <div class="rounded-xl bg-canvas px-4 py-3"><dt class="text-xs text-muted">Average payment</dt><dd class="text-2xl font-bold">{{ $payments['count'] > 0 ? Money::format(intdiv($payments['total'], $payments['count'])) : '—' }}</dd></div>
            <div class="rounded-xl bg-canvas px-4 py-3"><dt class="text-xs text-muted">Voided (not counted)</dt><dd class="text-2xl font-bold">{{ Money::format($payments['voided_total']) }}</dd><dd class="text-xs text-muted">{{ $payments['voided_count'] }} voided</dd></div>
        </dl>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <div>
                <h3 class="font-bold">By payment method</h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="data-table !min-w-0">
                        <caption class="sr-only">Valid collections by method</caption>
                        <thead><tr><th scope="col">Method</th><th scope="col" class="text-right">Payments</th><th scope="col" class="text-right">Amount</th><th scope="col" class="w-32"><span class="sr-only">Share</span></th></tr></thead>
                        <tbody>
                        @foreach ($payments['methods'] as $method)
                            <tr>
                                <th scope="row">{{ $method['label'] }}</th>
                                <td class="text-right">{{ $method['count'] }}</td>
                                <td class="text-right">{{ Money::format($method['total']) }}</td>
                                <td><div class="meter" role="img" aria-label="{{ $pct($method['total'], $payments['total']) }}% of collections"><span style="width: {{ $pct($method['total'], $payments['total']) }}%"></span></div></td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot><tr><th scope="row" class="px-4 py-3 text-left">Total</th><td class="px-4 py-3 text-right font-bold">{{ $payments['count'] }}</td><td class="px-4 py-3 text-right font-bold">{{ Money::format($payments['total']) }}</td><td></td></tr></tfoot>
                    </table>
                </div>
            </div>
            <div>
                <h3 class="font-bold">By day</h3>
                @if ($payments['daily']->isEmpty())
                    <p class="mt-3 text-sm text-muted">No valid payments in this period.</p>
                @else
                    <div class="mt-3 max-h-80 overflow-auto">
                        <table class="data-table !min-w-0">
                            <caption class="sr-only">Valid collections per day</caption>
                            <thead><tr><th scope="col">Date</th><th scope="col" class="text-right">Payments</th><th scope="col" class="text-right">Amount</th></tr></thead>
                            <tbody>
                            @foreach ($payments['daily'] as $day)
                                <tr><td>{{ $day['day']->format('D, M j, Y') }}</td><td class="text-right">{{ $day['count'] }}</td><td class="text-right">{{ Money::format($day['total']) }}</td></tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </section>

    {{-- ===================== Outstanding ===================== --}}
    <section id="outstanding" class="card mt-6 scroll-mt-24" aria-labelledby="outstanding-heading">
        <h2 id="outstanding-heading" class="card-title">Outstanding balances <span class="text-sm font-normal text-muted">· snapshot</span></h2>
        <p class="card-subtitle">Open (pending) transactions that still owe money. Cancelled, approved and expired transactions owe nothing.</p>
        <p class="mt-4 text-sm"><strong class="text-2xl">{{ Money::format($outstanding['total']) }}</strong>
            <span class="text-muted">across {{ $outstanding['count'] }} {{ str('transaction')->plural($outstanding['count']) }}</span></p>

        @if ($outstandingList->isEmpty())
            <x-empty-state icon="check" title="Nothing outstanding" message="Every open transaction is fully paid."/>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="data-table">
                    <caption class="sr-only">Largest outstanding balances</caption>
                    <thead><tr><th scope="col">Reference</th><th scope="col">Member</th><th scope="col">Type</th><th scope="col">Opened</th><th scope="col" class="text-right">Amount</th><th scope="col" class="text-right">Paid</th><th scope="col" class="text-right">Balance</th></tr></thead>
                    <tbody>
                    @foreach ($outstandingList as $txn)
                        <tr>
                            <td class="font-mono text-xs"><a href="{{ route('admin.transactions.show', $txn) }}" class="hover:underline">{{ $txn->reference_no }}</a></td>
                            <td>{{ $txn->member?->fullName() }}</td>
                            <td>{{ $txn->type->label() }} · {{ $txn->level->name }}</td>
                            <td class="whitespace-nowrap">{{ $txn->created_at->format('M j, Y') }}</td>
                            <td class="text-right">{{ Money::formatDecimal($txn->amount) }}</td>
                            <td class="text-right">{{ Money::formatDecimal($txn->amount_paid) }}</td>
                            <td class="text-right font-bold">{{ $txn->formattedBalance() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($outstanding['count'] > $outstandingList->count())
                <p class="mt-3 text-xs text-muted">Showing the {{ $outstandingList->count() }} largest of {{ $outstanding['count'] }}. <a class="underline" href="{{ route('admin.transactions.index', ['status' => 'pending']) }}">See all pending transactions</a>.</p>
            @endif
        @endif
    </section>

    {{-- ===================== Tasks ===================== --}}
    <section id="tasks" class="card mt-6 scroll-mt-24" aria-labelledby="tasks-heading">
        <h2 id="tasks-heading" class="card-title">Tasks</h2>
        <p class="card-subtitle">Status counts are a snapshot of active (non-archived) tasks. Completions use the period {{ $period }}.</p>

        <dl class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach (TaskStatus::cases() as $status)
                <div class="rounded-xl bg-canvas px-4 py-3"><dt class="text-xs text-muted">{{ $status->label() }}</dt><dd class="text-2xl font-bold">{{ $tasks['status'][$status->value] }}</dd></div>
            @endforeach
            <div class="rounded-xl bg-canvas px-4 py-3"><dt class="text-xs text-muted">Overdue (open, past due)</dt><dd @class(['text-2xl font-bold', 'text-red-700' => $tasks['overdue'] > 0])>{{ $tasks['overdue'] }}</dd></div>
            <div class="rounded-xl bg-canvas px-4 py-3"><dt class="text-xs text-muted">Overall completion</dt><dd class="text-2xl font-bold">{{ $tasks['completion_rate'] === null ? '—' : $tasks['completion_rate'].'%' }}</dd></div>
            <div class="rounded-xl bg-canvas px-4 py-3"><dt class="text-xs text-muted">Created in period</dt><dd class="text-2xl font-bold">{{ $tasks['created_in_period'] }}</dd></div>
            <div class="rounded-xl bg-canvas px-4 py-3"><dt class="text-xs text-muted">Completed in period</dt><dd class="text-2xl font-bold">{{ $tasks['completed_in_period'] }}</dd>
                <dd class="text-xs text-muted">{{ $tasks['on_time_rate'] === null ? 'No completions' : $tasks['completed_on_time'].' on time ('.$tasks['on_time_rate'].'%)' }}</dd></div>
        </dl>
        <p class="mt-3 text-xs text-muted">{{ $tasks['archived'] }} archived {{ str('task')->plural($tasks['archived']) }} not included in the status counts.</p>
    </section>

    {{-- ===================== Workload ===================== --}}
    <section id="workload" class="card mt-6 scroll-mt-24" aria-labelledby="workload-heading">
        <h2 id="workload-heading" class="card-title">Task workload and progress</h2>
        <p class="card-subtitle">Open tasks currently assigned to each member (former staff accounts appear only through their historical tasks); completions in {{ $period }}.</p>

        @if ($workload->isEmpty())
            <x-empty-state icon="users" title="No assignees yet" message="Assign tasks to members with an active membership to see their workload here."/>
        @else
            <div class="mt-5 overflow-x-auto">
                <table class="data-table">
                    <caption class="sr-only">Workload per assignee</caption>
                    <thead><tr><th scope="col">Assignee</th><th scope="col" class="text-right">To Do</th><th scope="col" class="text-right">In Progress</th><th scope="col" class="text-right">On Hold</th><th scope="col" class="text-right">Open</th><th scope="col" class="text-right">Overdue</th><th scope="col" class="text-right">Completed in period</th><th scope="col" class="w-40">Average progress (open)</th></tr></thead>
                    <tbody>
                    @foreach ($workload as $row)
                        <tr>
                            <th scope="row"><a href="{{ route('admin.tasks.index', ['assignee' => $row['user']->id]) }}" class="font-bold hover:underline">{{ $row['user']->name }}</a>
                                @if ($row['user']->isMember() && $row['user']->member)<span class="block font-mono text-xs text-muted">{{ $row['user']->member->membership_no }}</span>@endif
                                @if ($row['user']->isFormerStaff())<x-badge color="gray">Former staff</x-badge>@endif
                                @unless ($row['user']->is_active)<x-badge color="gray">Inactive</x-badge>@endunless</th>
                            <td class="text-right">{{ $row['todo'] }}</td>
                            <td class="text-right">{{ $row['in_progress'] }}</td>
                            <td class="text-right">{{ $row['on_hold'] }}</td>
                            <td class="text-right font-bold">{{ $row['open'] }}</td>
                            <td @class(['text-right', 'font-bold text-red-700' => $row['overdue'] > 0])>{{ $row['overdue'] }}</td>
                            <td class="text-right">{{ $row['completed_in_period'] }}</td>
                            <td>
                                @if ($row['average_progress'] === null)
                                    <span class="text-xs text-muted">No open tasks</span>
                                @else
                                    <x-progress-bar :value="$row['average_progress']" show-value/>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
