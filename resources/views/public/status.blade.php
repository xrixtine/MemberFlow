@php use App\Enums\TransactionStatus; use App\Support\Money; @endphp
<x-layouts.guest title="Application status">
    <x-slot:head><meta name="robots" content="noindex, nofollow"></x-slot:head>

    <p class="eyebrow mb-2">Application status</p>
    <h1 class="text-3xl font-bold">Hello, {{ $firstName }}</h1>
    <p class="mt-2 text-sm text-muted">This private page shows the current state of your membership application.</p>

    <dl class="mt-8 divide-y divide-line rounded-2xl border border-line bg-white text-sm">
        <div class="flex items-center justify-between gap-4 px-5 py-4">
            <dt class="text-muted">Application reference</dt>
            <dd class="font-mono font-bold">{{ $transaction?->reference_no ?? '—' }}</dd>
        </div>
        <div class="flex items-center justify-between gap-4 px-5 py-4">
            <dt class="text-muted">Application status</dt>
            <dd>@if ($transaction)<x-badge :status="$transaction->status"/>@else — @endif</dd>
        </div>
        <div class="flex items-center justify-between gap-4 px-5 py-4">
            <dt class="text-muted">Email verification</dt>
            <dd>
                @if ($verified)
                    <x-badge color="green">Verified</x-badge>
                @else
                    <x-badge color="amber">Not verified yet</x-badge>
                @endif
            </dd>
        </div>
        @if ($transaction)
            <div class="flex items-center justify-between gap-4 px-5 py-4">
                <dt class="text-muted">Amount due</dt>
                <dd class="font-bold">{{ Money::formatDecimal($transaction->amount) }}</dd>
            </div>
            <div class="flex items-center justify-between gap-4 px-5 py-4">
                <dt class="text-muted">Amount paid</dt>
                <dd class="font-bold">{{ Money::formatDecimal($transaction->amount_paid) }}</dd>
            </div>
            <div class="flex items-center justify-between gap-4 px-5 py-4">
                <dt class="text-muted">Remaining balance</dt>
                <dd @class(['font-bold', 'text-red-700' => $transaction->balanceCents() > 0])>{{ $transaction->formattedBalance() }}</dd>
            </div>
        @endif
    </dl>

    <div class="mt-6 text-sm text-muted">
        @if (! $verified)
            <p>Please verify your email using the link we sent you. <a href="{{ route('apply.resend') }}" class="font-bold text-ink underline">Request a new verification link</a>.</p>
        @elseif ($transaction?->status === TransactionStatus::Pending)
            <p>Next step: pay the remaining balance at the membership office.</p>
        @elseif ($transaction?->status === TransactionStatus::Paid)
            <p>Fully paid — your application is waiting for approval by the membership office.</p>
        @elseif ($transaction?->status === TransactionStatus::Approved)
            <p>Your membership has been approved. Welcome!</p>
        @elseif ($transaction?->status === TransactionStatus::Cancelled)
            <p>This application was cancelled. Please contact the membership office if you have questions.</p>
        @endif
    </div>
    <p class="mt-6 text-xs text-muted">Keep this link private. It expires automatically; a new one is included in each verification email.</p>
</x-layouts.guest>
