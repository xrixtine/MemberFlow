<x-layouts.guest title="Application submitted">
    <x-slot:head><meta name="robots" content="noindex, nofollow"></x-slot:head>

    <span class="mb-4 flex size-14 items-center justify-center rounded-2xl bg-lime text-charcoal">
        <x-icon name="mail" class="size-7"/>
    </span>
    <p class="eyebrow mb-2">Thank you</p>
    <h1 class="text-3xl font-bold">Check your email</h1>
    <p class="mt-3 text-sm text-muted" role="status">
        We have received your submission. If this is a new application, we are sending a verification link to the email
        address you entered. The link is valid for {{ $ttlHours }} hours.
    </p>

    <ol class="mt-6 space-y-3 text-sm">
        <li class="flex gap-3"><span class="badge-charcoal">1</span> Open the email and click <strong>Verify my email address</strong>.</li>
        <li class="flex gap-3"><span class="badge-charcoal">2</span> Pay the membership fee at the office.</li>
        <li class="flex gap-3"><span class="badge-charcoal">3</span> The office approves your application and your membership becomes active.</li>
    </ol>

    <p class="mt-6 text-sm text-muted">
        Didn’t receive anything after a few minutes? Check your spam folder, then
        <a href="{{ route('apply.resend') }}" class="font-bold text-ink underline">request a new verification link</a>.
    </p>
</x-layouts.guest>
