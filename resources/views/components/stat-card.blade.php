@props(['label', 'value', 'icon' => 'dashboard', 'hint' => null, 'accent' => false, 'href' => null])
@php $tag = $href ? 'a' : 'div'; @endphp
<{{ $tag }} @if($href) href="{{ $href }}" @endif
    {{ $attributes->class([
        'block rounded-2xl border p-5 transition-colors',
        'border-charcoal bg-charcoal text-white' => $accent,
        'border-line bg-white' => ! $accent,
        'hover:border-lime-deep' => $href,
    ]) }}>
    <div class="flex items-start justify-between gap-3">
        <p @class(['text-xs font-bold uppercase tracking-wider', 'text-sidebar-text' => $accent, 'text-muted' => ! $accent])>{{ $label }}</p>
        <span @class(['flex size-9 items-center justify-center rounded-xl', 'bg-lime text-charcoal' => $accent, 'bg-canvas text-ink' => ! $accent])>
            <x-icon :name="$icon" class="size-5"/>
        </span>
    </div>
    <p @class(['mt-3 text-3xl font-bold tracking-tight', 'text-lime' => $accent])>{{ $value }}</p>
    @if ($hint)
        <p @class(['mt-1 text-xs', 'text-sidebar-text' => $accent, 'text-muted' => ! $accent])>{{ $hint }}</p>
    @endif
</{{ $tag }}>
