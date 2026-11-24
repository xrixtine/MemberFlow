@php use App\Enums\TransactionStatus; use App\Enums\TransactionType; use App\Support\Money; @endphp
<x-layouts.app title="Transactions">
    <x-page-header eyebrow="Membership" title="Transactions"
                   subtitle="Membership applications and renewals with their official reference numbers.">
        <form method="POST" action="{{ route('admin.transactions.expire') }}"
              data-confirm="Run the expiry check now? Approved periods that ended before today, and their members, will be marked expired.">
            @csrf
            <button type="submit" class="btn-secondary" data-loading="Checking…">
                <x-icon name="clock" class="size-4"/> Run expiry check
                @if ($dueForExpiry > 0)<span class="badge-amber">{{ $dueForExpiry }} due</span>@endif
            </button>
        </form>
        <a href="{{ route('admin.transactions.create') }}" class="btn-primary"><x-icon name="receipt" class="size-4"/> New transaction</a>
    </x-page-header>

    <div class="mb-6 flex flex-wrap gap-2" aria-label="Transactions by status">
        @foreach (TransactionStatus::cases() as $status)
            <a href="{{ route('admin.transactions.index', ['status' => $status->value]) }}"
               @class(['inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-bold',
                       'border-charcoal bg-charcoal text-lime' => $filters['status'] === $status,
                       'border-line bg-white text-ink hover:border-lime-deep' => $filters['status'] !== $status])>
                {{ $status->label() }} <span class="opacity-70">{{ $statusCounts[$status->value] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('admin.transactions.index') }}" class="card mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-6" role="search">
        <div class="md:col-span-2">
            <label for="q" class="form-label">Search</label>
            <input id="q" type="search" name="q" value="{{ $filters['q'] }}" class="form-input" placeholder="TXN reference, member name or MF-number">
        </div>
        <x-form.select name="status" label="Status" :options="TransactionStatus::options()" :value="$filters['status']?->value" placeholder="All statuses"/>
        <x-form.select name="type" label="Type" :options="TransactionType::options()" :value="$filters['type']?->value" placeholder="All types"/>
        <x-form.input name="from" type="date" label="Created from" :value="$filters['from']"/>
        <x-form.input name="to" type="date" label="Created to" :value="$filters['to']"/>
        <div class="flex flex-wrap gap-2 md:col-span-2 xl:col-span-6">
            <button type="submit" class="btn-dark">Apply filters</button>
            @if ($hasFilters)
                <a href="{{ route('admin.transactions.index') }}" class="btn-secondary">Clear</a>
            @endif
        </div>
    </form>

    @if ($transactions->isEmpty())
        <div class="card">
            @if ($hasFilters)
                <x-empty-state icon="receipt" title="No transactions match your filters" message="Try a different search or clear the filters.">
                    <a href="{{ route('admin.transactions.index') }}" class="btn-secondary">Clear filters</a>
                </x-empty-state>
            @else
                <x-empty-state icon="receipt" title="No transactions yet"
                               message="Applications are created when members are registered, or from here.">
                    <a href="{{ route('admin.transactions.create') }}" class="btn-primary">New transaction</a>
                </x-empty-state>
            @endif
        </div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Member</th>
                    <th scope="col">Type · Level</th>
                    <th scope="col" class="text-right">Amount</th>
                    <th scope="col" class="text-right">Balance</th>
                    <th scope="col">Status</th>
                    <th scope="col">Created</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($transactions as $transaction)
                    <tr>
                        <td class="font-mono text-xs"><a class="hover:underline" href="{{ route('admin.transactions.show', $transaction) }}">{{ $transaction->reference_no }}</a></td>
                        <td>
                            <span class="font-bold">{{ $transaction->member->fullName() }}</span>
                            <span class="block font-mono text-xs text-muted">{{ $transaction->member->membership_no }}</span>
                        </td>
                        <td>{{ $transaction->type->label() }}<span class="block text-xs text-muted">{{ $transaction->level->name }}</span></td>
                        <td class="text-right">{{ Money::formatDecimal($transaction->amount) }}</td>
                        <td @class(['text-right', 'font-bold text-red-700' => $transaction->balanceCents() > 0 && $transaction->status->acceptsPayments()])>{{ $transaction->formattedBalance() }}</td>
                        <td><x-badge :status="$transaction->status"/></td>
                        <td class="whitespace-nowrap">{{ $transaction->created_at->format('M j, Y') }}</td>
                        <td class="text-right"><a href="{{ route('admin.transactions.show', $transaction) }}" class="btn-secondary btn-sm">Open</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $transactions->links() }}
    @endif
</x-layouts.app>
