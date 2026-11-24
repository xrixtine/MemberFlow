<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Current weather from OpenWeather (server-side only; the API key never reaches the browser).
 *
 *  - No key configured     → "not_configured" without any HTTP request.
 *  - Coordinates are rounded to 2 decimals (~1 km) before leaving the server: enough for
 *    weather, and a member's exact location is never shared with the provider.
 *  - Successful results are cached per rounded location (default 10 minutes).
 *  - Failures are cached briefly (60 s) so an outage is not hammered.
 *  - A global upstream budget (50 calls/minute) stays under the free-tier limit.
 *  - Timeouts, HTTP errors and malformed payloads return an "unavailable" result —
 *    weather is never invented. Logs contain the failure reason only (no key, URL or coordinates).
 *
 * @phpstan-type Result array{status: 'ok'|'unavailable', reason?: string, message?: string, data?: array, cached?: bool}
 */
class WeatherService
{
    public const MESSAGES = [
        'not_configured' => 'Weather is not configured (no OpenWeather API key).',
        'timeout' => 'The weather service did not respond in time. Please try again later.',
        'invalid_key' => 'The weather service rejected the API key.',
        'busy' => 'The weather service is busy. Please try again in a minute.',
        'error' => 'The weather service is unavailable right now.',
        'invalid_response' => 'The weather service returned an unexpected response.',
        'invalid_location' => 'The location is not valid.',
    ];

    public function isConfigured(): bool
    {
        return filled(config('services.openweather.key'));
    }

    /** @return Result */
    public function current(float $latitude, float $longitude): array
    {
        if (! $this->isConfigured()) {
            return $this->unavailable('not_configured');
        }
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return $this->unavailable('invalid_location');
        }

        $lat = round($latitude, 2);
        $lon = round($longitude, 2);
        $key = sprintf('weather:v1:%.2f:%.2f', $lat, $lon);

        if (is_array($cached = Cache::get($key))) {
            return ['status' => 'ok', 'data' => $cached, 'cached' => true];
        }
        if (is_string($reason = Cache::get($key.':failed'))) {
            return $this->unavailable($reason);
        }

        $budget = (int) config('services.openweather.upstream_per_minute', 50);
        if (! RateLimiter::attempt('openweather-upstream', $budget, fn () => true, 60)) {
            return $this->unavailable('busy');
        }

        [$data, $reason] = $this->fetch($lat, $lon);

        if ($data === null) {
            Cache::put($key.':failed', $reason, (int) config('services.openweather.failure_cache_seconds', 60));
            Log::warning('OpenWeather request failed', ['reason' => $reason]);

            return $this->unavailable($reason);
        }

        Cache::put($key, $data, now()->addMinutes((int) config('services.openweather.cache_minutes', 10)));

        return ['status' => 'ok', 'data' => $data, 'cached' => false];
    }

    /** @return array{0: ?array, 1: ?string} normalised data, or null and a failure reason */
    private function fetch(float $lat, float $lon): array
    {
        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.openweather.timeout', 5))
                ->connectTimeout((int) config('services.openweather.connect_timeout', 3))
                ->get((string) config('services.openweather.url'), [
                    'lat' => $lat,
                    'lon' => $lon,
                    'units' => 'metric',
                    'lang' => 'en',
                    'appid' => config('services.openweather.key'),
                ]);
        } catch (Throwable) {
            // ConnectionException (timeout, DNS, refused). Its message contains the URL
            // including the API key, so it is deliberately not logged or returned.
            return [null, 'timeout'];
        }

        if ($response->status() === 401) {
            return [null, 'invalid_key'];
        }
        if ($response->status() === 429) {
            return [null, 'busy'];
        }
        if (! $response->successful()) {
            return [null, 'error'];
        }

        $data = $this->normalise($response->json());

        return $data === null ? [null, 'invalid_response'] : [$data, null];
    }

    /** Validate and normalise an OpenWeather "current weather" payload; null when malformed. */
    private function normalise(mixed $json): ?array
    {
        if (! is_array($json)
            || ! is_numeric($json['main']['temp'] ?? null)
            || ! is_string($json['weather'][0]['description'] ?? null)
            || ! is_numeric($json['dt'] ?? null)) {
            return null;
        }

        $tz = config('app.timezone');
        $time = fn ($ts) => is_numeric($ts) ? CarbonImmutable::createFromTimestamp((int) $ts, $tz) : null;
        $num = fn ($v, int $precision = 1) => is_numeric($v) ? round((float) $v, $precision) : null;

        return [
            'location' => is_string($json['name'] ?? null) && $json['name'] !== '' ? $json['name'] : null,
            'description' => ucfirst($json['weather'][0]['description']),
            'condition' => is_string($json['weather'][0]['main'] ?? null) ? $json['weather'][0]['main'] : null,
            'temperature' => $num($json['main']['temp']),
            'feels_like' => $num($json['main']['feels_like'] ?? null),
            'humidity' => is_numeric($json['main']['humidity'] ?? null) ? (int) $json['main']['humidity'] : null,
            'pressure' => is_numeric($json['main']['pressure'] ?? null) ? (int) $json['main']['pressure'] : null,
            'wind_speed' => $num($json['wind']['speed'] ?? null),
            'clouds' => is_numeric($json['clouds']['all'] ?? null) ? (int) $json['clouds']['all'] : null,
            'observed_at' => $time($json['dt'])?->toIso8601String(),
            'observed_label' => $time($json['dt'])?->format('M j, g:i A'),
            'sunrise' => $time($json['sys']['sunrise'] ?? null)?->format('g:i A'),
            'sunset' => $time($json['sys']['sunset'] ?? null)?->format('g:i A'),
            'units' => ['temperature' => '°C', 'wind' => 'm/s'],
        ];
    }

    /** @return Result */
    private function unavailable(string $reason): array
    {
        return ['status' => 'unavailable', 'reason' => $reason, 'message' => self::MESSAGES[$reason] ?? self::MESSAGES['error']];
    }
}
