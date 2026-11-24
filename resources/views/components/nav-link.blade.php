@props(['href', 'icon', 'active' => false])
<a href="{{ $href }}" @if($active) aria-current="page" @endif
   {{ $attributes->class(['nav-link', 'nav-link-active' => $active]) }}>
    <x-icon :name="$icon" class="size-5 shrink-0"/>
    <span>{{ $slot }}</span>
</a>
