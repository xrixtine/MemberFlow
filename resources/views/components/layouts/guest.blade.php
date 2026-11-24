@props(['title' => null, 'wide' => false])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{ $head ?? '' }}
</head>
<body class="min-h-screen">
<div class="grid min-h-screen lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
    {{-- Brand panel --}}
    <aside class="hidden flex-col justify-between bg-charcoal p-12 text-white lg:flex">
        <a href="{{ url('/') }}" class="text-3xl font-extrabold tracking-tighter">memberflow<span class="text-lime">.</span></a>
        <div>
            <p class="eyebrow !text-sidebar-label">Membership · Transactions · Tasks</p>
            <h2 class="mt-4 text-4xl font-bold leading-tight !text-white">Every member, payment and task — in one calm place.</h2>
            <p class="mt-4 max-w-md text-sm leading-relaxed text-sidebar-text">
                Register members, process applications and renewals, record payments, and keep your team's work on track.
            </p>
        </div>
        <p class="text-xs text-sidebar-label">{{ config('memberflow.organisation') }}</p>
    </aside>

    <main class="flex items-center justify-center px-4 py-10 sm:px-8">
        <div @class(['w-full', 'max-w-2xl' => $wide, 'max-w-md' => ! $wide])>
            <a href="{{ url('/') }}" class="mb-8 block text-2xl font-extrabold tracking-tighter lg:hidden">memberflow<span class="text-lime-deep">.</span></a>
            <x-flash/>
            {{ $slot }}
        </div>
    </main>
</div>
</body>
</html>
