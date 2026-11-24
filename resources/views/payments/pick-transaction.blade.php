@php use App\Support\Money; @endphp
<x-layouts.app title="Record payment">
    <x-page-header eyebrow="Payments" title="Record payment" subtitle="Step 1 of 2 — choose a pending transaction with an outstanding balance.">
        <a href="{{ route('admin.payments.index') }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Payments</a>
    </x-page-header>

    <form method="GET" action="{{ route('admin.payments.create') }}" class="card mb-6 flex flex-col gap-3 sm:flex-row sm:items-end" role="search">
        <div class="flex-1">
            <label for="q" class="form-label">Find a transaction</label>
            <input id="q" type="search" name="q" value="{{ $q }}" class="form-input" placeholder="TXN reference, member name or MF-number" autofocus>
        </div>
        <button type="submit" class="btn-dark">Search</button>
        @if ($q !== '')<a href="{{ route('admin.payments.create') }}" class="btn-secondary">Show all</a>@endif
    </form>

    @if ($open->isEmpty())
        <div class="card">
            <x-empty-state icon="receipt" :title="$q === '' ? 'Nothing to collect' : 'No matching transactions'"
                           message="Only pending applications and renewals with a remaining balance can receive payments."/>
        </div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Reference</th><th>Member</th><th>Type · Level</th><th class="text-right">Amount</th><th class="text-right">Balance</th><th></th></tr></thead>
                <tbody>
                @foreach ($open as $transaction)
                    <tr>
                        <td class="font-mono text-xs">{{ $transaction->reference_no }}</td>
                        <td><span class="font-bold">{{ $transaction->member->fullName() }}</span><span class="block font-mono text-xs text-muted">{{ $transaction->member->membership_no }}</span></td>
                        <td>{{ $transaction->type->label() }}<span class="block text-xs text-muted">{{ $transaction->level->name }}</span></td>
                        <td class="text-right">{{ Money::formatDecimal($transaction->amount) }}</td>
                        <td class="text-right font-bold text-red-700">{{ $transaction->formattedBalance() }}</td>
                        <td class="text-right"><a class="btn-primary btn-sm" href="{{ route('admin.payments.create', ['transaction' => $transaction->id]) }}">Select</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.app>
