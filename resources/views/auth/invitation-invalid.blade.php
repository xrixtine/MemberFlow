<x-layouts.guest title="Invitation unavailable">
    <x-slot:head><meta name="robots" content="noindex, nofollow"></x-slot:head>

    <span class="mb-4 flex size-14 items-center justify-center rounded-2xl bg-[#fff1d6] text-[#855610]">
        <x-icon name="alert" class="size-7"/>
    </span>
    <h1 class="text-3xl font-bold">This invitation can’t be used</h1>
    <p class="mt-3 text-sm text-muted" role="alert">{{ $message }}</p>
    <a href="{{ route('login') }}" class="btn-secondary mt-8">Go to sign in</a>
</x-layouts.guest>
