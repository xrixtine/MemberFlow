@php use App\Support\Money; @endphp
{{-- The signed-in member's OWN charges and payments. Read-only; receipts open only for their own payments (PaymentPolicy). --}}
<x-layouts.app title="My payments">
    <x-page-header eyebrow="Payments" title="My payments" subtitle="What you were charged, what you paid, and printable receipts."/>

    <section aria-label="Payment summary" class="grid grid-cols-2 gap-4 lg:grid-cols-4" data-payment-summary>
        <x-stat-card label="Total charges" :value="$summary['total_charges']" icon="receipt" hint="Applications and renewals (cancelled ones excluded)"/>
        <x-stat-card label="Total paid" :value="$summary['total_paid']" icon="wallet" accent :hint="$summary['count'].' valid '.str('payment')->plural($summary['count']).' (voided excluded)'"/>
        <x-stat-card label="Balance due" :value="$summary['outstanding']" icon="alert" :hint="$summary['outstanding_cents'] > 0 ? 'Pay at the membership office' : 'Nothing to pay'"/>
        <x-stat-card label="Last payment" :value="$summary['last']?->paid_at->format('M j, Y') ?? '—'" icon="calendar" :hint="$summary['last']?->receipt_no"/>
    </section>

    <section class="card mt-8" aria-labelledby="charges-heading">
        <h2 id="charges-heading" class="card-title">Charges</h2>
        <p class="card-subtitle">Each application or renewal, with the amount paid so far.</p>
        @if ($charges->isEmpty())
            <x-empty-state icon="receipt" title="No charges yet"/>
        @else
            <div class="table-wrap mt-5">
                <table class="data-table">
                    <thead><tr><th>Reference</th><th>For</th><th class="text-right">Amount</th><th class="text-right">Paid</th><th class="text-right">Balance</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach ($charges as $charge)
                        <tr>
                            <td class="whitespace-nowrap font-mono text-xs">{{ $charge->reference_no }}</td>
                            <td>{{ $charge->type->label() }} · {{ $charge->level->name }}</td>
                            <td class="text-right">{{ Money::formatDecimal($charge->amount) }}</td>
                            <td class="text-right">{{ Money::formatDecimal($charge->amount_paid) }}</td>
                            <td class="text-right font-bold">{{ $charge->formattedBalance() }}</td>
                            <td><x-badge :status="$charge->status"/></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="card mt-6" aria-labelledby="payments-heading">
        <h2 id="payments-heading" class="card-title">Payment history</h2>
        @if ($summary['voided_count'] > 0)
            <p class="card-subtitle">Voided payments are shown struck through and are not counted in your totals.</p>
        @endif
        @if ($payments->isEmpty())
            <x-empty-state icon="wallet" title="No payments yet" message="Payments recorded by the membership office will appear here."/>
        @else
            <div class="table-wrap mt-5">
                <table class="data-table">
                    <thead><tr><th>Receipt</th><th>Date</th><th>For</th><th>Method</th><th class="text-right">Amount</th><th>Status</th><th><span class="sr-only">Receipt</span></th></tr></thead>
                    <tbody>
                    @foreach ($payments as $payment)
                        <tr @class(['opacity-60' => $payment->isVoided()])>
                            <td class="whitespace-nowrap font-mono text-xs">{{ $payment->receipt_no }}</td>
                            <td class="whitespace-nowrap">{{ $payment->paid_at->format('M j, Y') }}</td>
                            <td>{{ $payment->transaction->type->label() }} <span class="block whitespace-nowrap font-mono text-xs text-muted">{{ $payment->transaction->reference_no }}</span></td>
                            <td>{{ $payment->method->label() }}</td>
                            <td @class(['text-right', 'line-through' => $payment->isVoided()])>{{ $payment->formattedAmount() }}</td>
                            <td>@if ($payment->isVoided())<x-badge color="red">Void</x-badge>@else<x-badge color="green">Valid</x-badge>@endif</td>
                            <td class="text-right"><a href="{{ route('my.payments.receipt', $payment) }}" class="btn-secondary btn-sm" target="_blank" rel="noopener">Receipt</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $payments->links() }}
        @endif
    </section>
</x-layouts.app>
