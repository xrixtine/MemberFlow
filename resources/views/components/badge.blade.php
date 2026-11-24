@props(['status' => null, 'color' => 'gray'])
{{--
    <x-badge :status="$member->status"/>        → uses the enum's label() and color()
    <x-badge color="green">Custom</x-badge>
--}}
@php
    $color = $status?->color() ?? $color;
    $allowed = ['green', 'amber', 'red', 'gray', 'blue', 'lime', 'charcoal'];
    $class = 'badge-'.(in_array($color, $allowed, true) ? $color : 'gray');
@endphp
<span {{ $attributes->class($class) }}>{{ $slot->isNotEmpty() ? $slot : $status?->label() }}</span>
