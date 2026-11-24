<?php

namespace Tests\Feature\Integrations;

use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\MembershipLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * R7 — member-side location and weather: a member sees only their OWN location, and only
 * while consent is on file. The OpenWeather key never reaches the browser.
 */
class MemberLocationTest extends TestCase
{
    use RefreshDatabase;

    private const MAPS_KEY = 'test-browser-maps-key';

    private const WEATHER_KEY = 'ow-secret-server-key-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_maps.key' => self::MAPS_KEY, 'services.openweather.key' => self::WEATHER_KEY]);
    }

    private function account(array $memberAttributes): User
    {
        return User::factory()->member(Member::factory()->active()->create($memberAttributes))->create();
    }

    public function test_member_sees_their_own_consented_location_map_and_weather(): void
    {
        $user = $this->account(['latitude' => '14.5547000', 'longitude' => '121.0244000', 'location_consent_at' => now()->subDay()]);

        $this->actingAs($user)->get(route('my.membership'))->assertOk()
            ->assertSee('My location on file')
            ->assertSee('data-location-section="member"', false)
            ->assertSee('data-location-view', false)
            ->assertSee('14.5547000, 121.0244000')
            ->assertSee('Map showing your location on file')
            ->assertSee('At your location on file')
            ->assertSee(e(route('weather', ['lat' => '14.5547000', 'lon' => '121.0244000'])), false)
            ->assertSee('contact the membership office')
            ->assertDontSee(self::WEATHER_KEY)
            ->assertDontSee('Edit the member');                 // admin wording is not shown to members
    }

    public function test_no_location_is_shown_without_consent_or_coordinates(): void
    {
        $noConsent = $this->account(['latitude' => '14.6000000', 'longitude' => '121.0000000', 'location_consent_at' => null]);
        $noCoordinates = $this->account(['latitude' => null, 'longitude' => null]);

        $this->actingAs($noConsent)->get(route('my.membership'))->assertOk()
            ->assertDontSee('My location on file')->assertDontSee('14.6000000')->assertDontSee('data-location-view', false);
        $this->actingAs($noCoordinates)->get(route('my.membership'))->assertOk()->assertDontSee('My location on file');
    }

    public function test_members_never_see_another_members_location(): void
    {
        $user = $this->account(['latitude' => '14.5000000', 'longitude' => '121.0000000', 'location_consent_at' => now()]);
        $other = Member::factory()->active()->create(['latitude' => '10.3157000', 'longitude' => '123.8854000', 'location_consent_at' => now()]);

        $this->actingAs($user)->get(route('my.membership'))->assertOk()->assertDontSee('10.3157000')->assertDontSee('123.8854000');
        $this->get(route('dashboard'))->assertOk()->assertDontSee('10.3157000');
        $this->get(route('admin.members.show', $other))->assertForbidden();
        $this->get(route('admin.members.edit', $other))->assertForbidden();
    }

    public function test_admin_location_features_stay_admin_only(): void
    {
        $member = Member::factory()->active()->create(['latitude' => '14.5000000', 'longitude' => '121.0000000', 'location_consent_at' => now()]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.members.show', $member))->assertOk()
            ->assertSee('data-location-section="admin"', false)->assertSee('Location consent recorded')->assertDontSee(self::WEATHER_KEY);
        $this->get(route('admin.members.edit', $member))->assertOk()->assertSee('data-location-picker', false)->assertSee('location_consent', false);

        $this->actingAs($this->account([]))->get(route('admin.members.create'))->assertForbidden();
        $this->put(route('admin.members.update', $member), ['latitude' => '1', 'longitude' => '1', 'location_consent' => '1'])->assertForbidden();
        $this->assertSame('14.5000000', $member->fresh()->latitude);
    }

    public function test_member_weather_comes_from_the_server_proxy_with_rounded_coordinates(): void
    {
        Http::fake(['api.openweathermap.org/*' => Http::response([
            'name' => 'Makati', 'dt' => 1795400000, 'weather' => [['main' => 'Clouds', 'description' => 'broken clouds']],
            'main' => ['temp' => 30.4, 'feels_like' => 35.1, 'humidity' => 70], 'wind' => ['speed' => 3.6], 'clouds' => ['all' => 75],
        ])]);
        $user = $this->account(['latitude' => '14.5547123', 'longitude' => '121.0244987', 'location_consent_at' => now()]);

        $this->actingAs($user)->getJson(route('weather', ['lat' => '14.5547123', 'lon' => '121.0244987']))->assertOk()
            ->assertJsonPath('data.temperature', 30.4)->assertJsonPath('data.description', 'Broken clouds')
            ->assertJsonPath('attribution', 'Weather data © OpenWeather')
            ->assertDontSee(self::WEATHER_KEY);

        Http::assertSent(fn ($request) => $request['lat'] == 14.55 && $request['lon'] == 121.02);   // ~1 km, never the exact point
    }

    public function test_pickers_and_maps_use_responsive_sizes(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.members.create'))->assertOk()
            ->assertSee('h-72 w-full sm:h-80', false)            // map height adapts to the screen
            ->assertSee('grid gap-5 sm:grid-cols-2', false);      // coordinate inputs stack on phones
        auth()->logout();   // the public form is for visitors (and needs at least one category and level)
        MembershipCategory::factory()->create();
        MembershipLevel::factory()->create();
        $this->get(route('apply.create'))->assertOk()->assertSee('data-location-picker', false)->assertDontSee(self::WEATHER_KEY);
    }
}
