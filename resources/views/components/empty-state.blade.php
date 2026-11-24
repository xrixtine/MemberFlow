@props(['title', 'message' => null, 'icon' => 'inbox'])
<div {{ $attributes->class('flex flex-col items-center px-6 py-12 text-center') }}>
    <span class="mb-4 flex size-14 items-center justify-center rounded-2xl bg-canvas text-muted">
        <x-icon :name="$icon" class="size-7"/>
    </span>
    <h3 class="text-base font-bold">{{ $title }}</h3>
    @if ($message)
        <p class="mt-1 max-w-sm text-sm leading-relaxed text-muted">{{ $message }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
