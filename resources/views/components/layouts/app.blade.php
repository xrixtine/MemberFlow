@props(['title' => null])
@php
    $user = auth()->user();

    // Navigation is declared once per role. Items whose routes do not exist yet
    // are not rendered, so the sidebar never shows a non-functional link.
    $sections = $user->isAdmin()
        ? [
            'Overview' => [
                ['route' => 'admin.dashboard', 'match' => 'admin.dashboard', 'icon' => 'dashboard', 'label' => 'Dashboard'],
                ['route' => 'admin.reports.index', 'match' => 'admin.reports.*', 'icon' => 'chart', 'label' => 'Reports'],
            ],
            'Membership' => [
                ['route' => 'admin.members.index', 'match' => 'admin.members.*', 'icon' => 'users', 'label' => 'Members'],
                ['route' => 'admin.transactions.index', 'match' => 'admin.transactions.*', 'icon' => 'receipt', 'label' => 'Transactions'],
                ['route' => 'admin.payments.index', 'match' => 'admin.payments.*', 'icon' => 'card', 'label' => 'Payments'],
                ['route' => 'admin.categories.index', 'match' => 'admin.categories.*', 'icon' => 'tag', 'label' => 'Categories'],
                ['route' => 'admin.levels.index', 'match' => 'admin.levels.*', 'icon' => 'layers', 'label' => 'Levels'],
            ],
            'Tasks & accounts' => [
                ['route' => 'admin.tasks.index', 'match' => 'admin.tasks.*', 'icon' => 'tasks', 'label' => 'Tasks'],
                ['route' => 'admin.users.index', 'match' => 'admin.users.*', 'icon' => 'user', 'label' => 'Users'],
                ['route' => 'admin.invitations.index', 'match' => 'admin.invitations.*', 'icon' => 'user-plus', 'label' => 'Invitations'],
                ['route' => 'admin.email-deliveries.index', 'match' => 'admin.email-deliveries.*', 'icon' => 'mail', 'label' => 'Email log'],
            ],
        ]
        : [
            // Members (the only other role that can sign in).
            'My membership' => [
                ['route' => 'dashboard', 'match' => 'dashboard', 'icon' => 'dashboard', 'label' => 'Dashboard'],
                ['route' => 'my.membership', 'match' => 'my.membership', 'icon' => 'user', 'label' => 'Membership'],
                ['route' => 'my.payments.index', 'match' => 'my.payments.*', 'icon' => 'wallet', 'label' => 'Payments'],
            ],
            'My work' => [
                ['route' => 'my.tasks.index', 'match' => 'my.tasks.*', 'icon' => 'tasks', 'label' => 'My Tasks'],
                ['route' => 'my.history', 'match' => 'my.history', 'icon' => 'clock', 'label' => 'Task history'],
            ],
        ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{ $head ?? '' }}
</head>
<body class="min-h-screen">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-lime focus:px-4 focus:py-2 focus:font-bold">
    Skip to content
</a>

<div class="flex min-h-screen">
    {{-- Sidebar --}}
    <aside id="sidebar"
           class="no-print fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-charcoal px-4 py-7 text-white transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0">
        <div class="mb-6 flex items-center justify-between px-3">
            <a href="{{ route('home') }}" class="text-[26px] font-extrabold tracking-tighter">memberflow<span class="text-lime">.</span></a>
            <button type="button" class="rounded-lg p-1 text-sidebar-text hover:text-white lg:hidden" data-sidebar-toggle aria-label="Close menu">
                <x-icon name="x"/>
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto" aria-label="Main">
            @foreach ($sections as $label => $items)
                @php $visible = array_filter($items, fn ($item) => Route::has($item['route'])); @endphp
                @if ($visible)
                    <div class="nav-label">{{ $label }}</div>
                    <div class="space-y-1">
                        @foreach ($visible as $item)
                            <x-nav-link :href="route($item['route'])" :icon="$item['icon']" :active="request()->routeIs($item['match'])">
                                {{ $item['label'] }}
                            </x-nav-link>
                        @endforeach
                    </div>
                @endif
            @endforeach
        </nav>

        <div class="mt-6 border-t border-white/10 px-3 pt-5 text-xs leading-relaxed text-sidebar-label">
            <span class="badge-charcoal border border-lime/30">{{ $user->role->label() }}</span>
            <p class="mt-3">Membership Management &amp; Task System</p>
        </div>
    </aside>
    <div id="sidebar-backdrop" class="no-print fixed inset-0 z-30 hidden bg-black/40 lg:hidden" data-sidebar-toggle></div>

    {{-- Main column --}}
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="no-print sticky top-0 z-20 flex items-center justify-between gap-4 border-b border-line bg-canvas/90 px-4 py-3 backdrop-blur sm:px-8">
            <button type="button" class="btn-secondary btn-sm lg:hidden" data-sidebar-toggle aria-controls="sidebar" aria-expanded="false">
                <x-icon name="menu" class="size-4"/> Menu
            </button>
            <div class="hidden text-sm text-muted lg:block">{{ now()->format('l, F j, Y') }}</div>

            <div class="relative">
                <button type="button" class="flex items-center gap-3 rounded-xl px-2 py-1.5 hover:bg-white" data-menu-toggle="user-menu" aria-haspopup="true" aria-expanded="false">
                    <span class="flex size-9 items-center justify-center rounded-full bg-charcoal text-sm font-bold text-lime">{{ $user->initials() }}</span>
                    <span class="hidden text-left sm:block">
                        <span class="block text-sm font-bold leading-tight">{{ $user->name }}</span>
                        <span class="block text-xs text-muted">{{ $user->email }}</span>
                    </span>
                </button>
                <div id="user-menu" data-menu class="absolute right-0 mt-2 hidden w-56 rounded-2xl border border-line bg-white p-2 shadow-lg">
                    <a href="{{ route('profile.edit') }}" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm hover:bg-field">
                        <x-icon name="settings" class="size-4"/> Profile settings
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50">
                            <x-icon name="logout" class="size-4"/> Sign out
                        </button>
                    </form>
                </div>
            </div>
        </header>

        @if ($user->isAdmin())
            @php $mailTracker = app(\App\Services\EmailDeliveryTracker::class); @endphp
            @if ($mailTracker->smtpMissingCredentials())
                <div class="no-print border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 sm:px-8" role="alert" data-mail-banner>
                    <strong>Email is misconfigured:</strong> MAIL_MAILER=smtp but the SMTP username or password is empty. Emails will fail.
                    <a href="{{ route('admin.email-deliveries.index') }}" class="font-bold underline">Email log</a>
                </div>
            @elseif ($mailTracker->isLogMode())
                <div class="no-print border-b border-[#f3dfb6] bg-[#fff1d6] px-4 py-2 text-sm text-[#7a5210] sm:px-8" role="status" data-mail-banner>
                    <strong>Email is in local log mode</strong> (MAIL_MAILER={{ $mailTracker->currentMailer() }}) — no real emails are being delivered.
                    <a href="{{ route('admin.email-deliveries.index') }}" class="font-bold underline">Email log</a>
                </div>
            @endif
        @endif

        <main id="main" class="flex-1 px-4 py-8 sm:px-8 lg:px-12">
            <x-flash/>
            {{ $slot }}
        </main>
    </div>
</div>
</body>
</html>
