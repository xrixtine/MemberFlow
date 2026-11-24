<x-layouts.guest title="Email verified">
    <x-slot:head><meta name="robots" content="noindex, nofollow"></x-slot:head>

    <span class="mb-4 flex size-14 items-center justify-center rounded-2xl bg-[#e3f5db] text-[#39742b]">
        <x-icon name="check" class="size-7"/>
    </span>
    <p class="eyebrow mb-2">Email verification</p>
    <h1 class="text-3xl font-bold">{{ $newlyVerified ? 'Your email is verified' : 'Your email was already verified' }}</h1>
    <p class="mt-3 text-sm text-muted" role="status">
        Thank you, {{ $member->first_name }}. Your email address is confirmed
        @if ($member->email_verified_at) ({{ $member->email_verified_at->format('F j, Y g:i A') }}) @endif.
    </p>

    <div class="alert-info mt-6" role="note">
        <x-icon name="alert" class="mt-0.5 size-4 shrink-0"/>
        <p>Verification does <strong>not</strong> approve your membership or record a payment. Next, pay the membership fee at
            the office; an administrator then approves your application.</p>
    </div>

    @if ($accountInvited ?? false)
        <div class="alert-info mt-4" role="note" data-account-invited>
            <x-icon name="mail" class="mt-0.5 size-4 shrink-0"/>
            <p>We also emailed you a link to <strong>activate your member account</strong>, where you can follow your membership,
                payments and receipts. The link works once and expires in {{ config('memberflow.invitation_ttl_hours', 72) }} hours.</p>
        </div>
    @endif

    <a href="{{ $statusUrl }}" class="btn-primary mt-8" rel="noreferrer">View my application status</a>
    <p class="mt-3 text-xs text-muted">This private status link is personal — please don’t share it.</p>
</x-layouts.guest>
