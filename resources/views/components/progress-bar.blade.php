@props(['value' => 0, 'label' => null, 'showValue' => false])
{{-- Progress is always available as text: in the label row, beside the bar (show-value), or to screen readers. --}}
@php $value = max(0, min(100, (int) $value)); @endphp
<div {{ $attributes->class('w-full') }}>
    @if ($label)
        <div class="mb-1 flex justify-between text-xs text-muted"><span>{{ $label }}</span><span>{{ $value }}%</span></div>
    @endif
    <div class="flex items-center gap-2">
        <div class="h-2 w-full overflow-hidden rounded-full bg-line" role="progressbar"
             aria-valuenow="{{ $value }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $label ?? 'Progress' }}">
            <div @class(['h-full rounded-full', 'bg-lime-deep' => $value === 100, 'bg-lime' => $value < 100]) style="width: {{ $value }}%"></div>
        </div>
        @if ($showValue && ! $label)
            <span class="w-10 shrink-0 text-right text-xs font-bold text-muted">{{ $value }}%</span>
        @endif
    </div>
</div>
