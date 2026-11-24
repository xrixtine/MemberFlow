@props(['latitude', 'longitude', 'consentAt' => null, 'audience' => 'admin'])
{{--
    Read-only location: an embedded map when Google Maps is configured, always a plain link.
    audience="member" is the member's view of their OWN record (shown only when consent is on file).
--}}
@php
    $mapsKey = config('services.google_maps.key');
    $query = rawurlencode($latitude.','.$longitude);
    $isMember = $audience === 'member';
@endphp
<section {{ $attributes->class('card') }} aria-labelledby="member-location-heading" data-location-section="{{ $audience }}">
    <h2 id="member-location-heading" class="card-title">{{ $isMember ? 'My location on file' : 'Location' }}</h2>
    <p class="card-subtitle font-mono">{{ $latitude }}, {{ $longitude }}</p>

    @if ($mapsKey)
        <div class="mt-4 overflow-hidden rounded-2xl border border-line bg-field" data-location-view
             data-lat="{{ $latitude }}" data-lng="{{ $longitude }}"
             data-maps-key="{{ $mapsKey }}" data-map-id="{{ config('services.google_maps.map_id') }}">
            <div data-map class="h-56 w-full" role="img" aria-label="{{ $isMember ? 'Map showing your location on file' : "Map showing the member's location" }}"></div>
        </div>
        <p data-map-status class="form-hint" aria-live="polite"></p>
    @else
        <p class="mt-3 text-sm text-muted" data-map-unavailable>The embedded map is unavailable (Google Maps is not configured).</p>
    @endif

    <a href="https://www.google.com/maps/search/?api=1&amp;query={{ $query }}" target="_blank" rel="noopener noreferrer"
       class="btn-secondary btn-sm mt-3"><x-icon name="map-pin" class="size-4"/> Open in Google Maps</a>
    @if ($isMember)
        <p class="mt-3 text-xs text-muted">You agreed to store this location{{ $consentAt ? ' on '.$consentAt->format('M j, Y') : '' }}. To change or remove it, contact the membership office.</p>
    @elseif ($consentAt)
        <p class="mt-3 text-xs text-muted">Location consent recorded {{ $consentAt->format('M j, Y g:i A') }}.</p>
    @else
        <p class="mt-3 text-xs text-[#855610]">No location consent is recorded for these coordinates. Edit the member to confirm consent or clear them.</p>
    @endif
</section>
