<x-layouts.app title="Profile settings">
    <x-page-header eyebrow="Account" title="Profile settings"
                   :subtitle="$user->isMember() ? 'Your sign-in details and password.' : 'Update your name, email address and password.'"/>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="card" aria-labelledby="profile-heading">
            <h2 id="profile-heading" class="card-title">Profile information</h2>
            <p class="card-subtitle">Signed in as {{ $user->role->label() }}.</p>

            @if ($user->isMember())
                <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">Name</dt><dd class="mt-1">{{ $user->name }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">Sign-in email</dt><dd class="mt-1 break-words">{{ $user->email }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase tracking-wider text-muted">Membership no.</dt><dd class="mt-1 font-mono">{{ $user->member?->membership_no ?? '—' }}</dd></div>
                </dl>
                <p class="mt-5 rounded-xl bg-canvas px-4 py-3 text-sm text-muted">
                    Your name and email come from your membership record. To change them, contact the membership office.
                    <a href="{{ route('my.membership') }}" class="font-bold text-ink hover:underline">View my membership</a>
                </p>
            @else
                <form method="POST" action="{{ route('profile.update') }}" class="mt-6 space-y-5">
                    @csrf
                    @method('PUT')
                    <x-form.input name="name" label="Full name" :value="$user->name" required autocomplete="name"/>
                    <x-form.input name="email" type="email" label="Email address" :value="$user->email" required autocomplete="email"/>
                    <button type="submit" class="btn-primary" data-loading="Saving…">Save profile</button>
                </form>
            @endif
        </section>

        <section class="card" aria-labelledby="password-heading">
            <h2 id="password-heading" class="card-title">Change password</h2>
            <p class="card-subtitle">At least 8 characters with upper- and lower-case letters and a number.</p>

            <form method="POST" action="{{ route('profile.password') }}" class="mt-6 space-y-5">
                @csrf
                @method('PUT')
                <x-form.input name="current_password" type="password" label="Current password" bag="updatePassword" required autocomplete="current-password"/>
                <x-form.input name="password" type="password" label="New password" bag="updatePassword" required autocomplete="new-password"/>
                <x-form.input name="password_confirmation" type="password" label="Confirm new password" bag="updatePassword" required autocomplete="new-password"/>
                <button type="submit" class="btn-dark" data-loading="Updating…">Update password</button>
            </form>
        </section>
    </div>
</x-layouts.app>
