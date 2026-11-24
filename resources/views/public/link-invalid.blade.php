@php
    $expired = $reason === 'expired';
    $title = $kind === 'status'
        ? ($expired ? 'This status link has expired' : 'This status link is not valid')
        : ($expired ? 'This verification link has expired' : 'This verification link is not valid');
@endphp
<x-layouts.guest :title="$title">
    <x-slot:head><meta name="robots" content="noindex, nofollow"></x-slot:head>

    <span class="mb-4 flex size-14 items-center justify-center rounded-2xl bg-[#fff1d6] text-[#855610]">
        <x-icon name="alert" class="size-7"/>
    </span>
    <h1 class="text-3xl font-bold">{{ $title }}</h1>
    <p class="mt-3 text-sm text-muted" role="alert">
        @if ($expired)
            Links in our emails stop working after a while for your security.
        @else
            The link may be incomplete, may have been changed, or may belong to an older email address.
            Make sure you opened the most recent link from your email.
        @endif
    </p>

    <p class="mt-4 text-sm text-muted">
        @if ($kind === 'status')
            The latest verification email also contains a fresh status link.
        @endif
        You can request a new verification email below. If your address is already verified, please contact the membership office.
    </p>

    <a href="{{ route('apply.resend') }}" class="btn-primary mt-8">Request a new link</a>
</x-layouts.guest>
