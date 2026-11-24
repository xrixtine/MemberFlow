@props(['delivery'])
{{-- Honest delivery badge with a tooltip explaining exactly what the status means. --}}
@php
    $status = $delivery->status;
    $stale = $delivery->isStale();
    // Queued with attempts: an earlier attempt failed and will be retried (an error was recorded),
    // or the worker is sending it right now (no error yet).
    $label = match (true) {
        $stale => 'Queued — worker not running?',
        $status->value === 'queued' && $delivery->attempts > 0 && filled($delivery->error_message) => 'Queued — retrying after an error',
        $status->value === 'queued' && $delivery->attempts > 0 => 'Sending — attempt in progress',
        default => $status->label(),
    };
@endphp
<span {{ $attributes->class('badge-'.($stale ? 'amber' : $status->color())) }} title="{{ $status->description() }}">{{ $label }}</span>
