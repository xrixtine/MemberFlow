@php $isMember = $invitation->role === \App\Enums\UserRole::Member; @endphp
<x-layouts.guest title="Activate your account">
    <x-slot:head><meta name="robots" content="noindex, nofollow"></x-slot:head>

    <p class="eyebrow mb-2">{{ $isMember ? 'Member account' : 'Staff invitation' }}</p>
    <h1 class="text-3xl font-bold">Activate your account</h1>
    <p class="mt-2 text-sm text-muted">
        @if ($isMember)
            Create a password for your MemberFlow <strong>member account</strong>
            @if ($invitation->member?->membership_no)(membership {{ $invitation->member->membership_no }})@endif.
            Activating it does not approve or pay for your membership.
        @else
            {{ $invitation->inviter?->name ?? 'An administrator' }} invited you to join MemberFlow as <strong>staff</strong>.
        @endif
        This link expires {{ $invitation->expires_at->format('M j, Y g:i A') }}.
    </p>

    <form method="POST" action="{{ route('invitation.accept', $token) }}" class="mt-8 space-y-5" novalidate>
        @csrf
        <div>
            <label for="invited_email" class="form-label">Email address</label>
            <input id="invited_email" type="email" value="{{ $invitation->email }}" class="form-input bg-canvas" readonly aria-describedby="invited_email_hint" autocomplete="username">
            <p id="invited_email_hint" class="form-hint">This is the address you were invited with; it becomes your sign-in email.</p>
        </div>
        @if ($isMember)
            <div>
                <label for="member_name" class="form-label">Name</label>
                <input id="member_name" type="text" value="{{ $invitation->name }}" class="form-input bg-canvas" readonly aria-describedby="member_name_hint">
                <p id="member_name_hint" class="form-hint">From your membership record. Contact the membership office to correct it.</p>
            </div>
        @else
            <x-form.input name="name" label="Your name" :value="$invitation->name" required maxlength="255" autocomplete="name"/>
        @endif
        <x-form.input name="password" type="password" label="Choose a password" required autocomplete="new-password"
                      hint="At least 8 characters with upper- and lower-case letters and a number."/>
        <x-form.input name="password_confirmation" type="password" label="Confirm password" required autocomplete="new-password"/>
        <button type="submit" class="btn-primary w-full py-3" data-loading="Activating…">Activate account</button>
    </form>
</x-layouts.guest>
