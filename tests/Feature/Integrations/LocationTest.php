<?php

namespace Tests\Feature\Integrations;

use App\Models\Member;
use App\Services\MembershipService;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Email\EmailTestCase;

/**
 * Google Maps picker fallback, location consent and server-side coordinate validation.
 */
class LocationTest extends EmailTestCase
{
    private const MAPS_KEY = 'test-browser-maps-key-abc123';

    private function adminPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Rosa', 'last_name' => 'Map', 'email' => self::email('rosa.map'),
            'membership_category_id' => $this->category->id, 'membership_level_id' => $this->gold->id,
            'create_application' => '1',
        ], $overrides);
    }

    // ---- Map availability -------------------------------------------------------

    public function test_forms_fall_back_to_manual_entry_without_a_maps_key(): void
    {
        config(['services.google_maps.key' => null]);

        foreach ([
            $this->get(route('apply.create')),
            $this->actingAs($this->admin)->get(route('admin.members.create')),
        ] as $response) {
            $response->assertOk()
                ->assertSee('The interactive map is unavailable')
                ->assertSee('name="latitude"', false)
                ->assertSee('name="longitude"', false)
                ->assertSee('name="location_consent"', false)
                ->assertDontSee('data-maps-key', false)
                ->assertDontSee('maps.googleapis.com', false);
        }
    }

    public function test_forms_render_the_map_picker_when_a_key_is_configured(): void
    {
        config(['services.google_maps.key' => self::MAPS_KEY]);

        $this->get(route('apply.create'))
            ->assertOk()
            ->assertSee('data-location-picker', false)
            ->assertSee('data-maps-key="'.self::MAPS_KEY.'"', false)
            ->assertSee('data-map-id="DEMO_MAP_ID"', false)
            ->assertSee('Loading the map…')
            ->assertSee('name="latitude"', false)                    // manual entry is always present
            ->assertDontSee('The interactive map is unavailable');

        $member = Member::factory()->for($this->category, 'category')->for($this->gold, 'level')->create([
            'latitude' => '14.5995000', 'longitude' => '120.9842000', 'location_consent_at' => now(),
        ]);
        $this->actingAs($this->admin)->get(route('admin.members.edit', $member))
            ->assertOk()
            ->assertSee('data-maps-key', false)
            ->assertSee('value="14.5995000"', false)
            ->assertSee('name="location_consent" value="1"', false);
    }

    public function test_public_form_never_shows_weather_or_the_openweather_key(): void
    {
        config(['services.openweather.key' => 'server-only-weather-key']);

        $this->get(route('apply.create'))->assertOk()
            ->assertDontSee('server-only-weather-key')
            ->assertDontSee('Current weather');
    }

    // ---- Consent and validation: public form --------------------------------------

    public function test_public_application_stores_coordinates_only_with_consent(): void
    {
        Notification::fake();

        $this->post(route('apply.store'), $this->applicationPayload(['latitude' => '14.5995', 'longitude' => '120.9842']))
            ->assertSessionHasErrors('location_consent');
        $this->assertSame(0, Member::count());

        $this->post(route('apply.store'), $this->applicationPayload(['latitude' => '14.5995', 'longitude' => '120.9842', 'location_consent' => '1']))
            ->assertRedirect(route('apply.submitted'));
        $member = Member::sole();
        $this->assertSame('14.5995000', $member->latitude);
        $this->assertNotNull($member->location_consent_at);
    }

    public function test_consent_without_coordinates_stores_nothing(): void
    {
        Notification::fake();

        $this->post(route('apply.store'), $this->applicationPayload(['location_consent' => '1']))->assertRedirect(route('apply.submitted'));

        $member = Member::sole();
        $this->assertNull($member->latitude);
        $this->assertNull($member->location_consent_at);
    }

    #[DataProvider('invalidCoordinates')]
    public function test_invalid_coordinates_are_rejected_on_both_forms(array $input, string $field): void
    {
        Notification::fake();

        $this->post(route('apply.store'), $this->applicationPayload($input + ['location_consent' => '1']))->assertSessionHasErrors($field);
        $this->actingAs($this->admin)->post(route('admin.members.store'), $this->adminPayload($input + ['location_consent' => '1']))->assertSessionHasErrors($field);

        $this->assertSame(0, Member::count());
    }

    public static function invalidCoordinates(): array
    {
        return [
            'latitude above 90' => [['latitude' => '90.0001', 'longitude' => '120'], 'latitude'],
            'latitude below -90' => [['latitude' => '-91', 'longitude' => '120'], 'latitude'],
            'longitude above 180' => [['latitude' => '14', 'longitude' => '180.5'], 'longitude'],
            'longitude below -180' => [['latitude' => '14', 'longitude' => '-181'], 'longitude'],
            'non-numeric latitude' => [['latitude' => '14N', 'longitude' => '120'], 'latitude'],
            'latitude only' => [['latitude' => '14.5'], 'longitude'],
            'longitude only' => [['longitude' => '120.9'], 'latitude'],
        ];
    }

    public function test_boundary_coordinates_are_accepted(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)->post(route('admin.members.store'), $this->adminPayload([
            'latitude' => '-90', 'longitude' => '180', 'location_consent' => '1',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('-90.0000000', Member::sole()->latitude);
    }

    // ---- Consent: admin register / edit ----------------------------------------------

    public function test_admin_registration_requires_and_records_consent(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)->post(route('admin.members.store'), $this->adminPayload(['latitude' => '14.5995', 'longitude' => '120.9842']))
            ->assertSessionHasErrors('location_consent');

        $this->actingAs($this->admin)->post(route('admin.members.store'), $this->adminPayload([
            'latitude' => '14.5995', 'longitude' => '120.9842', 'location_consent' => '1',
        ]))->assertRedirect();

        $member = Member::sole();
        $this->assertSame('120.9842000', $member->longitude);
        $this->assertEquals(now(), $member->location_consent_at);
    }

    public function test_editing_keeps_moves_and_clears_location_with_consent_tracking(): void
    {
        Notification::fake();
        $this->actingAs($this->admin)->post(route('admin.members.store'), $this->adminPayload([
            'latitude' => '14.5995', 'longitude' => '120.9842', 'location_consent' => '1',
        ]));
        $member = Member::sole();
        $firstConsent = $member->location_consent_at;
        $edit = fn (array $o) => $this->actingAs($this->admin)->put(route('admin.members.update', $member), $this->adminPayload($o));

        // Unchanged coordinates keep the original consent time.
        $this->travel(1)->hour();
        $edit(['latitude' => '14.5995', 'longitude' => '120.9842', 'location_consent' => '1'])->assertSessionHasNoErrors();
        $this->assertEquals($firstConsent, $member->fresh()->location_consent_at);

        // Moving the pin needs consent and refreshes the consent time.
        $edit(['latitude' => '14.6500', 'longitude' => '121.0300'])->assertSessionHasErrors('location_consent');
        $edit(['latitude' => '14.6500', 'longitude' => '121.0300', 'location_consent' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('14.6500000', $member->fresh()->latitude);
        $this->assertTrue($member->fresh()->location_consent_at->gt($firstConsent));

        // Clearing the coordinates also clears the consent record.
        $edit(['latitude' => '', 'longitude' => ''])->assertSessionHasNoErrors();
        $this->assertNull($member->fresh()->latitude);
        $this->assertNull($member->fresh()->location_consent_at);
    }

    public function test_service_discards_coordinates_without_consent(): void
    {
        Notification::fake();

        $member = app(MembershipService::class)->register([
            'first_name' => 'No', 'last_name' => 'Consent', 'email' => self::email('no.consent'),
            'latitude' => '14.5', 'longitude' => '120.9',
            'membership_category_id' => $this->category->id, 'membership_level_id' => $this->gold->id,
        ], $this->admin);

        $this->assertNull($member->fresh()->latitude);
        $this->assertNull($member->fresh()->location_consent_at);
    }

    // ---- Member profile -----------------------------------------------------------------

    public function test_member_profile_shows_location_with_fallbacks(): void
    {
        config(['services.google_maps.key' => null, 'services.openweather.key' => null]);
        $member = Member::factory()->for($this->category, 'category')->for($this->gold, 'level')->create([
            'latitude' => '14.5995000', 'longitude' => '120.9842000', 'location_consent_at' => now(),
        ]);

        $this->actingAs($this->admin)->get(route('admin.members.show', $member))
            ->assertOk()
            ->assertSee('The embedded map is unavailable')
            ->assertSee('https://www.google.com/maps/search/?api=1&amp;query=14.5995000%2C120.9842000', false)
            ->assertSee('Location consent recorded')
            ->assertSee('Weather is unavailable: OpenWeather is not configured.');

        $noLocation = Member::factory()->for($this->category, 'category')->for($this->gold, 'level')->create();
        $this->actingAs($this->admin)->get(route('admin.members.show', $noLocation))
            ->assertOk()->assertDontSee('Open in Google Maps')->assertDontSee('Current weather');
    }

    public function test_member_profile_embeds_map_and_weather_widget_when_configured(): void
    {
        config(['services.google_maps.key' => self::MAPS_KEY, 'services.openweather.key' => 'server-only-weather-key']);
        $member = Member::factory()->for($this->category, 'category')->for($this->gold, 'level')->create([
            'latitude' => '14.5995000', 'longitude' => '120.9842000', 'location_consent_at' => now(),
        ]);

        $this->actingAs($this->admin)->get(route('admin.members.show', $member))
            ->assertOk()
            ->assertSee('data-location-view', false)
            ->assertSee('/weather?lat=14.5995000', false)
            ->assertDontSee('server-only-weather-key');
    }
}
