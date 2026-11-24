@props(['latitude', 'longitude', 'label' => null])
{{--
    Current weather for a location. Data comes from GET /weather (server-side
    WeatherService); the OpenWeather key is never in the page. Without a key, or when
    the service fails, an "unavailable" state is shown — weather is never invented.
--}}
@php $configured = app(\App\Services\WeatherService::class)->isConfigured(); @endphp
<section {{ $attributes->class('card') }} aria-labelledby="weather-heading-{{ $attributes->get('id', 'w') }}">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h2 id="weather-heading-{{ $attributes->get('id', 'w') }}" class="card-title">Current weather</h2>
            <p class="card-subtitle">{{ $label ?? number_format((float) $latitude, 4).', '.number_format((float) $longitude, 4) }}</p>
        </div>
        <x-icon name="cloud" class="size-6 text-muted"/>
    </div>

    @if (! $configured)
        <p class="mt-4 text-sm text-muted" data-weather-unavailable>
            Weather is unavailable: OpenWeather is not configured.
        </p>
    @else
        <div class="mt-4" data-weather data-url="{{ route('weather', ['lat' => $latitude, 'lon' => $longitude]) }}" aria-live="polite" aria-busy="true">
            <p class="text-sm text-muted" data-weather-loading>Loading weather…</p>
            <div data-weather-body hidden>
                <p class="text-3xl font-bold"><span data-w="temperature"></span><span class="text-lg font-normal text-muted">°C</span></p>
                <p class="mt-1 font-bold" data-w="description"></p>
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                    <dt class="text-muted">Feels like</dt><dd data-w="feels_like"></dd>
                    <dt class="text-muted">Humidity</dt><dd data-w="humidity"></dd>
                    <dt class="text-muted">Wind</dt><dd data-w="wind_speed"></dd>
                    <dt class="text-muted">Clouds</dt><dd data-w="clouds"></dd>
                    <dt class="text-muted">Sunrise / sunset</dt><dd data-w="sun"></dd>
                </dl>
                <p class="mt-3 text-xs text-muted"><span data-w="place"></span>Observed <span data-w="observed_label"></span> · Weather data © OpenWeather</p>
            </div>
            <p class="text-sm text-muted" data-weather-error hidden></p>
            <noscript><p class="text-sm text-muted">Weather needs JavaScript.</p></noscript>
        </div>
    @endif
</section>
