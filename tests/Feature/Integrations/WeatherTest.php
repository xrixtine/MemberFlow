<?php

namespace Tests\Feature\Integrations;

use App\Models\User;
use App\Services\WeatherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * OpenWeather integration. Every external call is faked — no real API is contacted.
 */
class WeatherTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-openweather-key-0123456789';

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(10, 0));
        config(['services.openweather.key' => self::KEY]);
        $this->admin = User::factory()->admin()->create();
        $this->staff = User::factory()->member()->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Manila',
            'dt' => 1794459600, // 2026-11-12 10:20 Asia/Manila
            'weather' => [['main' => 'Clouds', 'description' => 'broken clouds', 'icon' => '04d']],
            'main' => ['temp' => 30.46, 'feels_like' => 35.12, 'humidity' => 70, 'pressure' => 1010],
            'wind' => ['speed' => 3.6],
            'clouds' => ['all' => 75],
            'sys' => ['sunrise' => 1794434400, 'sunset' => 1794476400],
        ], $overrides);
    }

    private function fakeOk(array $overrides = []): void
    {
        Http::fake(['api.openweathermap.org/*' => Http::response($this->payload($overrides), 200)]);
    }

    // ---- Service ------------------------------------------------------------------

    public function test_success_is_normalised_and_uses_metric_units_and_rounded_coordinates(): void
    {
        $this->fakeOk();

        $result = app(WeatherService::class)->current(14.599512, 120.984222);

        $this->assertSame('ok', $result['status']);
        $this->assertFalse($result['cached']);
        $this->assertSame('Manila', $result['data']['location']);
        $this->assertSame('Broken clouds', $result['data']['description']);
        $this->assertSame(30.5, $result['data']['temperature']);
        $this->assertSame(35.1, $result['data']['feels_like']);
        $this->assertSame(70, $result['data']['humidity']);
        $this->assertSame(3.6, $result['data']['wind_speed']);
        $this->assertSame(75, $result['data']['clouds']);
        $this->assertNotNull($result['data']['observed_label']);

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.openweathermap.org/data/2.5/weather')
            && $r['units'] === 'metric'
            && (string) $r['lat'] === '14.6' && (string) $r['lon'] === '120.98'   // ~1 km precision only
            && $r['appid'] === self::KEY);
    }

    public function test_missing_key_returns_not_configured_without_any_request(): void
    {
        Http::fake();
        config(['services.openweather.key' => null]);

        $result = app(WeatherService::class)->current(14.6, 120.98);

        $this->assertSame(['status' => 'unavailable', 'reason' => 'not_configured', 'message' => WeatherService::MESSAGES['not_configured']], $result);
        Http::assertNothingSent();
    }

    public function test_results_are_cached_per_rounded_location(): void
    {
        $this->fakeOk();
        $weather = app(WeatherService::class);

        $weather->current(14.5995, 120.9842);
        $second = $weather->current(14.6012, 120.9811);       // rounds to the same 14.60 / 120.98

        $this->assertTrue($second['cached']);
        Http::assertSentCount(1);

        $weather->current(10.3157, 123.8854);                  // a different place
        Http::assertSentCount(2);

        $this->travel(11)->minutes();                          // cache expired
        $weather->current(14.5995, 120.9842);
        Http::assertSentCount(3);
    }

    public function test_timeout_is_unavailable_and_backs_off_without_leaking_the_key(): void
    {
        Log::spy();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 28: Operation timed out for https://api.openweathermap.org/data/2.5/weather?appid='.self::KEY);
        });
        $weather = app(WeatherService::class);

        $first = $weather->current(14.6, 120.98);
        $second = $weather->current(14.6, 120.98);

        $this->assertSame('unavailable', $first['status']);
        $this->assertSame('timeout', $first['reason']);
        $this->assertSame('timeout', $second['reason']);
        $this->assertSame(1, $attempts);                            // failure cached for 60 s
        $this->assertStringNotContainsString(self::KEY, json_encode($first));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($msg, $ctx) => $ctx === ['reason' => 'timeout']);

        $this->travel(61)->seconds();
        $weather->current(14.6, 120.98);
        $this->assertSame(2, $attempts);
    }

    public function test_http_errors_map_to_clear_reasons(): void
    {
        $current = 200;
        Http::fake(function () use (&$current) {
            return Http::response(['cod' => $current, 'message' => 'x'], $current);
        });

        foreach ([401 => 'invalid_key', 429 => 'busy', 500 => 'error', 404 => 'error'] as $status => $reason) {
            Cache::flush();
            $current = $status;

            $result = app(WeatherService::class)->current(14.6, 120.98);

            $this->assertSame('unavailable', $result['status'], "HTTP {$status}");
            $this->assertSame($reason, $result['reason'], "HTTP {$status}");
        }
    }

    public function test_invalid_or_incomplete_responses_are_rejected_not_guessed(): void
    {
        $bodies = [
            'not json' => Http::response('<html>oops</html>', 200),
            'missing temperature' => Http::response(['weather' => [['description' => 'clear']], 'main' => [], 'dt' => 1], 200),
            'missing description' => Http::response(['weather' => [], 'main' => ['temp' => 20], 'dt' => 1], 200),
            'string temperature' => Http::response(['weather' => [['description' => 'x']], 'main' => ['temp' => 'hot'], 'dt' => 1], 200),
        ];

        foreach ($bodies as $label => $response) {
            Cache::flush();
            Http::fake(['api.openweathermap.org/*' => $response]);

            $result = app(WeatherService::class)->current(14.6, 120.98);
            $this->assertSame('invalid_response', $result['reason'] ?? null, $label);
            $this->assertArrayNotHasKey('data', $result, $label);
        }
    }

    public function test_global_upstream_budget_is_enforced(): void
    {
        config(['services.openweather.upstream_per_minute' => 2]);
        $this->fakeOk();
        $weather = app(WeatherService::class);

        $weather->current(1.0, 1.0);
        $weather->current(2.0, 2.0);
        $third = $weather->current(3.0, 3.0);

        $this->assertSame('busy', $third['reason']);
        Http::assertSentCount(2);
    }

    // ---- Endpoint ---------------------------------------------------------------------

    public function test_endpoint_requires_sign_in(): void
    {
        Http::fake();
        $this->getJson(route('weather', ['lat' => 14.6, 'lon' => 120.98]))->assertUnauthorized();
        $this->get(route('weather', ['lat' => 14.6, 'lon' => 120.98]))->assertRedirect(route('login'));

        $inactive = User::factory()->member()->inactive()->create();
        // Deactivated accounts are signed out by the existing EnsureUserIsActive middleware.
        $this->actingAs($inactive)->getJson(route('weather', ['lat' => 14.6, 'lon' => 120.98]))->assertRedirect(route('login'));
        Http::assertNothingSent();
    }

    public function test_endpoint_returns_weather_for_admin_and_staff_without_exposing_the_key(): void
    {
        $this->fakeOk();

        foreach ([$this->admin, $this->staff] as $user) {
            $response = $this->actingAs($user)->getJson(route('weather', ['lat' => 14.5995, 'lon' => 120.9842]))
                ->assertOk()
                ->assertJsonPath('status', 'ok')
                ->assertJsonPath('data.temperature', 30.5)
                ->assertJsonPath('data.description', 'Broken clouds')
                ->assertJsonPath('attribution', 'Weather data © OpenWeather');
            $this->assertStringNotContainsString(self::KEY, $response->getContent());
        }
    }

    public function test_endpoint_validates_coordinates(): void
    {
        Http::fake();
        $this->actingAs($this->admin);

        $this->getJson(route('weather', ['lat' => 91, 'lon' => 120]))->assertUnprocessable()->assertJsonValidationErrors('lat');
        $this->getJson(route('weather', ['lat' => 14, 'lon' => -181]))->assertUnprocessable()->assertJsonValidationErrors('lon');
        $this->getJson(route('weather', ['lat' => 'abc', 'lon' => 120]))->assertUnprocessable();
        $this->getJson(route('weather'))->assertUnprocessable()->assertJsonValidationErrors(['lat', 'lon']);
        Http::assertNothingSent();
    }

    public function test_endpoint_reports_unavailable_with_503(): void
    {
        config(['services.openweather.key' => null]);
        $this->actingAs($this->staff)->getJson(route('weather', ['lat' => 14.6, 'lon' => 120.98]))
            ->assertStatus(503)
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonPath('reason', 'not_configured')
            ->assertJsonMissingPath('data');
    }

    public function test_endpoint_is_rate_limited_per_user(): void
    {
        $this->fakeOk();
        $this->actingAs($this->staff);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson(route('weather', ['lat' => 14.6, 'lon' => 120.98]))->assertOk();
        }
        $this->getJson(route('weather', ['lat' => 14.6, 'lon' => 120.98]))->assertStatus(429);

        $this->actingAs($this->admin)->getJson(route('weather', ['lat' => 14.6, 'lon' => 120.98]))->assertOk(); // separate bucket
        Http::assertSentCount(1); // all served from cache after the first call
    }

    // ---- Widgets ------------------------------------------------------------------------

    public function test_dashboards_show_the_widget_and_never_the_key(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()->assertSee('Current weather')->assertSee('Manila (office)')
            ->assertSee('data-weather', false)->assertSee('/weather?lat=14.5995', false)
            ->assertDontSee(self::KEY);

        $this->actingAs($this->staff)->get(route('dashboard'))
            ->assertOk()->assertSee('Current weather')->assertDontSee(self::KEY);
    }

    public function test_widget_shows_unavailable_state_without_a_key(): void
    {
        config(['services.openweather.key' => null]);
        Http::fake();

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Weather is unavailable: OpenWeather is not configured.')
            ->assertDontSee('data-weather data-url', false);
        Http::assertNothingSent();
    }
}
