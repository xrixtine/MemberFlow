<x-layouts.guest title="Resend verification email">
    <p class="eyebrow mb-2">Online application</p>
    <h1 class="text-3xl font-bold">Resend the verification email</h1>
    <p class="mt-2 text-sm text-muted">
        Enter the email address you applied with. If it belongs to an online application that still needs verification,
        we will send a new link. For your privacy, the response is the same for every address.
    </p>

    <form method="POST" action="{{ route('apply.resend.send') }}" class="mt-8 space-y-5" novalidate>
        @csrf
        <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
            <label for="website">Leave this field empty</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off" value="">
        </div>
        <x-form.input name="email" type="email" label="Email address" required maxlength="255" autocomplete="email" autofocus/>
        <button type="submit" class="btn-primary w-full py-3" data-loading="Sending…">Send a new link</button>
    </form>

    <p class="mt-6 text-sm text-muted">
        Haven’t applied yet? <a href="{{ route('apply.create') }}" class="font-bold text-ink underline">Apply for membership</a>.
    </p>
</x-layouts.guest>
