@php use App\Support\Money; @endphp
<x-layouts.app :title="'History · '.$member->fullName()">
    <x-page-header :eyebrow="'Member · '.$member->membership_no" title="Transaction history" :subtitle="$member->fullName()">
        <a href="{{ route('admin.members.show', $member) }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Back to profile</a>
        <a href="{{ route('admin.members.print', $member) }}" class="btn-secondary" target="_blank" rel="noopener">Print record</a>
    </x-page-header>

    <section aria-label="Totals" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Membership" :value="$member->status->label()" icon="user"
                     :hint="$member->membership_end ? 'Valid until '.$member->membership_end->format('M j, Y') : 'Not yet active'"/>
        <x-stat-card label="Total billed" :value="Money::format($totals['billed'])" icon="receipt" hint="Excludes cancelled transactions"/>
        <x-stat-card label="Total paid" :value="Money::format($totals['paid'])" icon="wallet" accent/>
        <x-stat-card label="Outstanding" :value="Money::format($totals['balance'])" icon="alert"
                     :hint="$totals['approved'].' approved '.str('period')->plural($totals['approved'])"/>
    </section>

    <section class="mt-8 space-y-4" aria-label="Transactions">
        @forelse ($member->transactions as $transaction)
            <article class="card">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <a href="{{ route('admin.transactions.show', $transaction) }}" class="font-mono text-sm font-bold hover:underline">{{ $transaction->reference_no }}</a>
                        <p class="mt-1 text-sm text-muted">
                            {{ $transaction->type->label() }} · {{ $transaction->level->name }} · {{ $transaction->validity_months }} months
                            · created {{ $transaction->created_at->format('M j, Y') }}
                        </p>
                        @if ($transaction->period_start)
                            <p class="mt-1 text-sm">Validity: <strong>{{ $transaction->period_start->format('M j, Y') }} – {{ $transaction->period_end->format('M j, Y') }}</strong>
                                @if ($transaction->approver) · approved by {{ $transaction->approver->name }} @endif</p>
                        @endif
                        @if ($transaction->cancellation_reason)
                            <p class="mt-1 text-sm text-red-700">Cancelled: {{ $transaction->cancellation_reason }}</p>
                        @endif
                    </div>
                    <div class="text-right">
                        <x-badge :status="$transaction->status"/>
                        <p class="mt-2 text-lg font-bold">{{ Money::formatDecimal($transaction->amount) }}</p>
                        <p class="text-xs text-muted">Paid {{ Money::formatDecimal($transaction->amount_paid) }} · Balance {{ $transaction->formattedBalance() }}</p>
                    </div>
                </div>

                @if ($transaction->payments->isNotEmpty())
                    <div class="mt-4 overflow-x-auto">
                        <table class="data-table">
                            <thead><tr><th>Receipt</th><th>Date</th><th>Method</th><th class="text-right">Amount</th><th>Status</th></tr></thead>
                            <tbody>
                            @foreach ($transaction->payments as $payment)
                                <tr>
                                    <td class="font-mono text-xs"><a class="hover:underline" href="{{ route('admin.payments.show', $payment) }}">{{ $payment->receipt_no }}</a></td>
                                    <td>{{ $payment->paid_at->format('M j, Y') }}</td>
                                    <td>{{ $payment->method->label() }}</td>
                                    <td @class(['text-right', 'line-through opacity-60' => $payment->isVoided()])>{{ $payment->formattedAmount() }}</td>
                                    <td>@if ($payment->isVoided())<span class="badge-red" title="{{ $payment->void_reason }}">Voided</span>@else<span class="badge-green">Valid</span>@endif</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="mt-3 text-xs text-muted">No payments recorded for this transaction.</p>
                @endif
            </article>
        @empty
            <div class="card">
                <x-empty-state icon="receipt" title="No transactions yet" message="This member has no applications or renewals on record.">
                    <a href="{{ route('admin.transactions.create', ['member' => $member->id]) }}" class="btn-primary">New transaction</a>
                </x-empty-state>
            </div>
        @endforelse
    </section>
</x-layouts.app>
