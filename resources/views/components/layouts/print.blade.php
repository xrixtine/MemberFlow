@props(['title'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-canvas print:bg-white">
<div class="no-print mx-auto flex max-w-3xl items-center justify-between gap-3 px-4 pt-6">
    <a href="{{ url()->previous() }}" class="btn-secondary btn-sm"><x-icon name="arrow-left" class="size-4"/> Back</a>
    <button type="button" class="btn-primary btn-sm" onclick="window.print()">Print</button>
</div>
<main class="mx-auto my-6 max-w-3xl rounded-2xl border border-line bg-white p-8 print:my-0 print:border-0 print:p-0">
    <header class="mb-8 flex items-start justify-between gap-4 border-b border-line pb-6">
        <div>
            <p class="text-2xl font-extrabold tracking-tighter">memberflow<span class="text-lime-deep">.</span></p>
            <p class="text-sm text-muted">{{ config('memberflow.organisation') }}</p>
        </div>
        <div class="text-right text-sm text-muted">
            <p class="font-bold text-ink">{{ $title }}</p>
            <p>Printed {{ now()->format('M j, Y g:i A') }}</p>
        </div>
    </header>
    {{ $slot }}
</main>
</body>
</html>
