@php use App\Enums\PaymentMethod; use App\Support\Money; @endphp
<x-layouts.app title="Payments">
    <x-page-header eyebrow="Membership" title="Payment history"
                   subtitle="Every payment recorded against a membership transaction. Voided payments stay on record but are excluded from totals.">
        <a href="{{ route('admin.payments.create') }}" class="btn-primary"><x-icon name="card" class="size-4"/> Record payment</a>
    </x-page-header>

    <form method="GET" action="{{ route('admin.payments.index') }}" class="card mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-6" role="search">
        <div class="md:col-span-2">
            <label for="q" class="form-label">Search</label>
            <input id="q" type="search" name="q" value="{{ $filters['q'] }}" class="form-input" placeholder="OR / TXN number, member name, MF-number">
        </div>
        <x-form.select name="method" label="Method" :options="PaymentMethod::options()" :value="$filters['method']?->value" placeholder="All methods"/>
        <x-form.select name="state" label="Status" :options="['valid' => 'Valid', 'voided' => 'Voided']" :value="$filters['state']" placeholder="All"/>
        <x-form.input name="from" type="date" label="Paid from" :value="$filters['from']"/>
        <x-form.input name="to" type="date" label="Paid to" :value="$filters['to']"/>
        <div class="flex flex-wrap items-center gap-2 md:col-span-2 xl:col-span-6">
            <button type="submit" class="btn-dark">Apply filters</button>
            @if ($hasFilters)
                <a href="{{ route('admin.payments.index') }}" class="btn-secondary">Clear</a>
            @endif
            <p class="ml-auto text-sm text-muted">Valid total for this view: <strong class="text-ink">{{ Money::format($totalCents) }}</strong></p>
        </div>
    </form>

    @if ($payments->isEmpty())
        <div class="card">
            @if ($hasFilters)
                <x-empty-state icon="card" title="No payments match your filters" message="Try a different search or clear the filters.">
                    <a href="{{ route('admin.payments.index') }}" class="btn-secondary">Clear filters</a>
                </x-empty-state>
            @else
                <x-empty-state icon="card" title="No payments yet" message="Payments recorded against applications and renewals will appear here.">
                    <a href="{{ route('admin.payments.create') }}" class="btn-primary">Record payment</a>
                </x-empty-state>
            @endif
        </div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th scope="col">Receipt</th>
                    <th scope="col">Member</th>
                    <th scope="col">Transaction</th>
                    <th scope="col">Method</th>
                    <th scope="col" class="text-right">Amount</th>
                    <th scope="col">Paid on</th>
                    <th scope="col">Status</th>
                    <th scope="col">Received by</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($payments as $payment)
                    <tr>
                        <td class="font-mono text-xs">{{ $payment->receipt_no }}</td>
                        <td>
                            <span class="font-bold">{{ $payment->transaction->member->fullName() }}</span>
                            <span class="block font-mono text-xs text-muted">{{ $payment->transaction->member->membership_no }}</span>
                        </td>
                        <td class="font-mono text-xs"><a class="hover:underline" href="{{ route('admin.transactions.show', $payment->transaction) }}">{{ $payment->transaction->reference_no }}</a></td>
                        <td><x-badge :status="$payment->method"/></td>
                        <td @class(['text-right font-bold', 'line-through opacity-60' => $payment->isVoided()])>{{ $payment->formattedAmount() }}</td>
                        <td class="whitespace-nowrap">{{ $payment->paid_at->format('M j, Y') }}</td>
                        <td>@if ($payment->isVoided())<span class="badge-red">Voided</span>@else<span class="badge-green">Valid</span>@endif</td>
                        <td>{{ $payment->receiver?->name }}</td>
                        <td class="text-right"><a href="{{ route('admin.payments.show', $payment) }}" class="btn-secondary btn-sm">View details</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $payments->links() }}
    @endif
</x-layouts.app>
