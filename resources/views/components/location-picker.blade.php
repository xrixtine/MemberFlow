@props([
    'latitude' => null,
    'longitude' => null,
    'consented' => false,
    'consentLabel' => 'I agree that MemberFlow may store these coordinates with the membership record.',
    'heading' => 'Location',
    'description' => null,
])
{{--
    Location picker: Google Maps (when GOOGLE_MAPS_KEY is set) + manual latitude/longitude.

    - Manual entry ALWAYS works; the map only fills the two inputs (progressive enhancement).
    - No key → a clear "map unavailable" note and no Google script is loaded at all.
    - Key set but the map fails (network, blocked, invalid/restricted key) → the script in
      resources/js/location.js shows an "unavailable" message and manual entry continues.
    - The server validates the coordinates and requires the consent checkbox whenever
      coordinates are present (App\Http\Requests\Concerns\ValidatesLocation).
--}}
@php
    $mapsKey = config('services.google_maps.key');
    $lat = old('latitude', $latitude);
    $lng = old('longitude', $longitude);
    $consentChecked = (bool) old('location_consent', $consented ? '1' : null);
@endphp
<section {{ $attributes->class('card') }} aria-labelledby="location-heading"
         data-location-picker
         data-default-lat="{{ config('memberflow.default_latitude') }}"
         data-default-lng="{{ config('memberflow.default_longitude') }}"
         @if ($mapsKey) data-maps-key="{{ $mapsKey }}" data-map-id="{{ config('services.google_maps.map_id') }}" @endif>
    <h2 id="location-heading" class="card-title">{{ $heading }} <span class="text-sm font-normal text-muted">(optional)</span></h2>
    <p class="card-subtitle">
        {{ $description ?? 'Choose a point on the map or type the coordinates. Leave both empty to skip.' }}
    </p>

    @if ($mapsKey)
        <div class="mt-5 overflow-hidden rounded-2xl border border-line bg-field">
            <div data-map class="h-72 w-full sm:h-80" role="application"
                 aria-label="Map. Click or tap to place the location marker; drag the marker to adjust it."></div>
        </div>
        <p data-map-status class="form-hint" aria-live="polite">Loading the map…</p>
        <noscript><p class="form-hint">The map needs JavaScript. Enter the coordinates below instead.</p></noscript>
    @else
        <div class="alert-info mt-5" role="note" data-map-unavailable>
            <x-icon name="alert" class="mt-0.5 size-4 shrink-0"/>
            <p>The interactive map is unavailable (Google Maps is not configured). You can still enter the latitude and longitude manually.</p>
        </div>
    @endif

    <div class="mt-5 grid gap-5 sm:grid-cols-2">
        <x-form.input name="latitude" label="Latitude" :value="$lat" inputmode="decimal" placeholder="14.5995000"
                      hint="Between -90 and 90" data-location-lat autocomplete="off"/>
        <x-form.input name="longitude" label="Longitude" :value="$lng" inputmode="decimal" placeholder="120.9842000"
                      hint="Between -180 and 180" data-location-lng autocomplete="off"/>
    </div>

    <div class="mt-3 flex flex-wrap gap-2">
        <button type="button" class="btn-secondary btn-sm" data-location-current hidden>Use my current location</button>
        <button type="button" class="btn-secondary btn-sm" data-location-clear>Clear location</button>
    </div>

    <label class="mt-4 flex items-start gap-3 rounded-xl border border-line bg-field p-4 text-sm" data-location-consent-box>
        <input type="checkbox" name="location_consent" value="1" class="mt-0.5 size-4 accent-[#5d7f24]" @checked($consentChecked)
               data-location-consent
               @error('location_consent') aria-invalid="true" aria-describedby="location_consent-error" @enderror>
        <span>{{ $consentLabel }} <span class="text-muted">Required only when coordinates are entered.</span></span>
    </label>
    @error('location_consent')
        <p id="location_consent-error" class="form-error">{{ $message }}</p>
    @enderror
</section>
