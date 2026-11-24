<x-layouts.guest title="Sign in">
    <p class="eyebrow mb-2">Welcome back</p>
    <h1 class="text-3xl font-bold">Sign in to MemberFlow</h1>
    <p class="mt-2 text-sm text-muted">Administrators and members use the same sign-in page. Member accounts are activated from an invitation email.</p>

    <form method="POST" action="{{ route('login.store') }}" class="mt-8 space-y-5" novalidate>
        @csrf

        <x-form.input name="email" type="email" label="Email address" autocomplete="username" required autofocus
                      placeholder="you@example.com"/>

        <x-form.input name="password" type="password" label="Password" autocomplete="current-password" required/>

        <label class="flex items-center gap-2 text-sm text-muted">
            <input type="checkbox" name="remember" value="1" class="size-4 rounded border-line accent-[#5d7f24]" @checked(old('remember'))>
            Keep me signed in on this device
        </label>

        <button type="submit" class="btn-primary w-full py-3" data-loading="Signing in…">Sign in</button>
    </form>

    <p class="mt-8 border-t border-line pt-6 text-sm text-muted">
        Not a member yet? <a href="{{ route('apply.create') }}" class="font-bold text-ink underline">Apply for membership online</a>.
    </p>
</x-layouts.guest>
