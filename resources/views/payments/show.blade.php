@php use App\Support\Money; @endphp
<x-layouts.app :title="$payment->receipt_no">
    <x-page-header eyebrow="Payment" :title="$payment->receipt_no"
                   :subtitle="$payment->formattedAmount().' · '.$payment->method->label().' · '.$payment->paid_at->format('F j, Y')">
        <a href="{{ route('admin.payments.index') }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Payments</a>
        <a href="{{ route('admin.payments.receipt', $payment) }}" class="btn-dark" target="_blank" rel="noopener">Print receipt</a>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            @if ($payment->isVoided())
                <div class="alert-error" role="status">
                    <x-icon name="alert" class="mt-0.5 size-4 shrink-0"/>
                    <p><strong>VOID</strong> — voided {{ $payment->voided_at->format('M j, Y g:i A') }} by {{ $payment->voider?->name }}.
                        Reason: {{ $payment->void_reason }}</p>
                </div>
            @endif

            <section class="card" aria-labelledby="payment-heading">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="payment-heading" class="card-title">Payment details</h2>
                    @if ($payment->isVoided())<span class="badge-red !text-xs">Voided</span>@else<span class="badge-green !text-xs">Valid</span>@endif
                </div>
                <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    @foreach ([
                        'Receipt no.' => $payment->receipt_no,
                        'Amount' => $payment->formattedAmount(),
                        'Method' => $payment->method->label(),
                        'External reference' => $payment->external_reference,
                        'Payment date' => $payment->paid_at->format('F j, Y'),
                        'Received by' => $payment->receiver?->name,
                        'Recorded' => $payment->created_at->format('M j, Y g:i A'),
                        'Remarks' => $payment->notes,
                    ] as $label => $value)
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wider text-muted">{{ $label }}</dt>
                            <dd class="mt-1 break-words">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <section class="card" aria-labelledby="txn-payments-heading">
                <h2 id="txn-payments-heading" class="card-title">All payments on {{ $payment->transaction->reference_no }}</h2>
                <div class="mt-5 overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Receipt</th><th>Date</th><th>Method</th><th class="text-right">Amount</th><th>Status</th></tr></thead>
                        <tbody>
                        @foreach ($payment->transaction->payments as $item)
                            <tr @class(['bg-lime/20' => $item->is($payment)])>
                                <td class="font-mono text-xs"><a class="hover:underline" href="{{ route('admin.payments.show', $item) }}">{{ $item->receipt_no }}</a></td>
                                <td>{{ $item->paid_at->format('M j, Y') }}</td>
                                <td>{{ $item->method->label() }}</td>
                                <td @class(['text-right', 'line-through opacity-60' => $item->isVoided()])>{{ $item->formattedAmount() }}</td>
                                <td>@if ($item->isVoided())<span class="badge-red">Voided</span>@else<span class="badge-green">Valid</span>@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div class="space-y-6">
            <section class="card" aria-labelledby="txn-heading">
                <h2 id="txn-heading" class="card-title">Transaction</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-xs font-bold uppercase text-muted">Reference</dt>
                        <dd><a class="font-mono hover:underline" href="{{ route('admin.transactions.show', $payment->transaction) }}">{{ $payment->transaction->reference_no }}</a></dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Member</dt>
                        <dd>{{ $payment->transaction->member->fullName() }} · <span class="font-mono">{{ $payment->transaction->member->membership_no }}</span></dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Type · Level</dt><dd>{{ $payment->transaction->type->label() }} · {{ $payment->transaction->level->name }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Amount · Paid · Balance</dt>
                        <dd>{{ Money::formatDecimal($payment->transaction->amount) }} · {{ Money::formatDecimal($payment->transaction->amount_paid) }} · <strong>{{ $payment->transaction->formattedBalance() }}</strong></dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Status</dt><dd><x-badge :status="$payment->transaction->status"/></dd></div>
                </dl>
            </section>

            @if ($voidBlocker === null)
                <section class="card" aria-labelledby="void-heading">
                    <h2 id="void-heading" class="card-title">Void payment</h2>
                    <p class="card-subtitle">Use only for a payment recorded by mistake. The record is kept, marked VOID, and the balance is recalculated.</p>
                    <form method="POST" action="{{ route('admin.payments.void', $payment) }}" class="mt-4 space-y-4"
                          data-confirm="Void {{ $payment->receipt_no }} ({{ $payment->formattedAmount() }})? This cannot be undone.">
                        @csrf @method('PATCH')
                        <x-form.textarea name="void_reason" label="Reason" rows="2" required maxlength="255"/>
                        <button type="submit" class="btn-danger w-full" data-loading="Voiding…">Void payment</button>
                    </form>
                </section>
            @elseif (! $payment->isVoided())
                <p class="text-xs text-muted">{{ $voidBlocker }}</p>
            @endif
        </div>
    </div>
</x-layouts.app>
