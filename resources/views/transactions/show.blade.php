@php use App\Enums\TransactionStatus; use App\Support\Money; @endphp
<x-layouts.app :title="$transaction->reference_no">
    <x-page-header :eyebrow="$transaction->type->label()" :title="$transaction->reference_no"
                   :subtitle="'Created '.$transaction->created_at->format('F j, Y g:i A').($transaction->creator ? ' by '.$transaction->creator->name : '')">
        <a href="{{ route('admin.transactions.index') }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Transactions</a>
        <a href="{{ route('admin.members.history', $transaction->member) }}" class="btn-secondary">Member history</a>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            {{-- Amounts --}}
            <section aria-label="Amounts" class="grid gap-4 sm:grid-cols-3">
                <x-stat-card label="Amount due" :value="Money::formatDecimal($transaction->amount)" icon="receipt"
                             :hint="$transaction->level->name.' · '.$transaction->validity_months.' months (recorded at creation)'"/>
                <x-stat-card label="Amount paid" :value="Money::formatDecimal($transaction->amount_paid)" icon="wallet"/>
                <x-stat-card label="Balance" :value="$transaction->formattedBalance()" icon="alert" :accent="$transaction->balanceCents() > 0"/>
            </section>

            <section class="card" aria-labelledby="details-heading">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="details-heading" class="card-title">Transaction details</h2>
                    <x-badge :status="$transaction->status" class="!text-xs"/>
                </div>
                <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    @foreach ([
                        'Reference no.' => $transaction->reference_no,
                        'Type' => $transaction->type->label(),
                        'Level (snapshot)' => $transaction->level->name,
                        'Validity (snapshot)' => $transaction->validity_months.' months',
                        'Created' => $transaction->created_at->format('M j, Y g:i A'),
                        'Validity period' => $transaction->period_start
                            ? $transaction->period_start->format('M j, Y').' – '.$transaction->period_end->format('M j, Y')
                            : 'Set when approved',
                        'Approved' => $transaction->approved_at ? $transaction->approved_at->format('M j, Y g:i A').' by '.($transaction->approver?->name ?? 'unknown') : null,
                        'Cancelled' => $transaction->cancelled_at ? $transaction->cancelled_at->format('M j, Y g:i A').' by '.($transaction->canceller?->name ?? 'unknown') : null,
                        'Cancellation reason' => $transaction->cancellation_reason,
                        'Remarks' => $transaction->remarks,
                    ] as $label => $value)
                        @if ($value !== null)
                            <div>
                                <dt class="text-xs font-bold uppercase tracking-wider text-muted">{{ $label }}</dt>
                                <dd class="mt-1 break-words">{{ $value }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
            </section>

            <section class="card" aria-labelledby="payments-heading">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="payments-heading" class="card-title">Payments</h2>
                        <p class="card-subtitle">It becomes "Paid" automatically when valid payments reach the full amount.</p>
                    </div>
                    @if ($paymentBlocker === null)
                        <a href="{{ route('admin.payments.create', ['transaction' => $transaction->id]) }}" class="btn-primary">
                            <x-icon name="card" class="size-4"/> Record payment
                        </a>
                    @endif
                </div>
                @if ($transaction->payments->isEmpty())
                    <x-empty-state icon="wallet" title="No payments recorded"
                                   :message="$transaction->status === TransactionStatus::Pending ? 'Outstanding balance: '.$transaction->formattedBalance().'.' : null"/>
                @else
                    <div class="mt-5 overflow-x-auto">
                        <table class="data-table">
                            <thead><tr><th>Receipt</th><th>Date</th><th>Method</th><th>Received by</th><th class="text-right">Amount</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                            @foreach ($transaction->payments as $payment)
                                <tr>
                                    <td class="font-mono text-xs"><a class="hover:underline" href="{{ route('admin.payments.show', $payment) }}">{{ $payment->receipt_no }}</a></td>
                                    <td>{{ $payment->paid_at->format('M j, Y') }}</td>
                                    <td>{{ $payment->method->label() }}</td>
                                    <td>{{ $payment->receiver?->name }}</td>
                                    <td @class(['text-right', 'line-through opacity-60' => $payment->isVoided()])>{{ $payment->formattedAmount() }}</td>
                                    <td>@if ($payment->isVoided())<span class="badge-red">Voided</span>@else<span class="badge-green">Valid</span>@endif</td>
                                    <td class="text-right"><a class="btn-secondary btn-sm" href="{{ route('admin.payments.receipt', $payment) }}" target="_blank" rel="noopener">Receipt</a></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>

        <div class="space-y-6">
            <section class="card" aria-labelledby="member-heading">
                <h2 id="member-heading" class="card-title">Member</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-xs font-bold uppercase text-muted">Name</dt>
                        <dd>@if ($transaction->member->trashed()) {{ $transaction->member->fullName() }} <span class="badge-gray">Deleted</span>
                            @else <a class="font-bold hover:underline" href="{{ route('admin.members.show', $transaction->member) }}">{{ $transaction->member->fullName() }}</a>@endif</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Membership no.</dt><dd class="font-mono">{{ $transaction->member->membership_no }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Member status</dt><dd><x-badge :status="$transaction->member->status"/> <x-badge :status="$transaction->member->source"/></dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Email</dt>
                        <dd>{{ $transaction->member->email }}
                            @if ($transaction->member->isEmailVerified())<span class="badge-green">Verified</span>@else<span class="badge-amber">Not verified</span>@endif</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Current validity</dt>
                        <dd>{{ $transaction->member->membership_end ? $transaction->member->membership_start?->format('M j, Y').' – '.$transaction->member->membership_end->format('M j, Y') : 'Not yet active' }}</dd></div>
                </dl>
            </section>

            {{-- Approve --}}
            @if (! in_array($transaction->status, [TransactionStatus::Approved, TransactionStatus::Expired, TransactionStatus::Cancelled], true))
                <section class="card" aria-labelledby="approve-heading">
                    <h2 id="approve-heading" class="card-title">Approval</h2>
                    @if ($approvalBlocker === null)
                        @if ($previewPeriod)
                            <p class="mt-2 text-sm">Approving today makes the membership valid
                                <strong>{{ $previewPeriod[0]->format('M j, Y') }} – {{ $previewPeriod[1]->format('M j, Y') }}</strong>.</p>
                        @endif
                        <form method="POST" action="{{ route('admin.transactions.approve', $transaction) }}" class="mt-4"
                              data-confirm="Approve {{ $transaction->reference_no }} and activate the membership?">
                            @csrf @method('PATCH')
                            <button type="submit" class="btn-primary w-full" data-loading="Approving…">Approve transaction</button>
                        </form>
                    @else
                        <p class="mt-2 text-sm text-muted">{{ $approvalBlocker }}</p>
                        <button type="button" class="btn-primary mt-4 w-full" disabled aria-disabled="true">Approve transaction</button>
                        @if ($transaction->member && ! $transaction->member->trashed() && $transaction->member->source === \App\Enums\MemberSource::Online && ! $transaction->member->isEmailVerified())
                            <form method="POST" action="{{ route('admin.members.send-verification', $transaction->member) }}" class="mt-3">
                                @csrf
                                <button type="submit" class="btn-secondary w-full" data-loading="Queuing…">Resend verification email</button>
                            </form>
                        @endif
                    @endif
                </section>
            @endif

            {{-- Cancel --}}
            @if ($cancellationBlocker === null)
                <section class="card" aria-labelledby="cancel-heading">
                    <h2 id="cancel-heading" class="card-title">Cancel transaction</h2>
                    <p class="card-subtitle">Only unpaid pending transactions can be cancelled. The record is kept.</p>
                    <form method="POST" action="{{ route('admin.transactions.cancel', $transaction) }}" class="mt-4 space-y-4"
                          data-confirm="Cancel {{ $transaction->reference_no }}? This cannot be undone.">
                        @csrf @method('PATCH')
                        <x-form.textarea name="cancellation_reason" label="Reason" rows="2" required maxlength="255"/>
                        <button type="submit" class="btn-danger w-full" data-loading="Cancelling…">Cancel transaction</button>
                    </form>
                </section>
            @elseif ($transaction->status === TransactionStatus::Pending)
                <p class="text-xs text-muted">{{ $cancellationBlocker }}</p>
            @endif
        </div>
    </div>
</x-layouts.app>
