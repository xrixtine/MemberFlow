<?php

namespace Tests\Feature\Email;

use App\Enums\EmailDeliveryStatus;
use App\Enums\MemberSource;
use App\Enums\MemberStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\EmailDelivery;
use App\Models\Member;
use App\Models\MembershipTransaction;
use App\Models\User;
use App\Notifications\ApplicationAlreadyExists;
use App\Notifications\MembershipApplicationReceived;
use App\Notifications\VerifyApplicantEmail;
use App\Services\PublicApplicationService;
use App\Support\FormTimer;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

class PublicApplicationTest extends EmailTestCase
{
    public function test_form_is_public_and_shows_levels_with_fee_validity_and_benefits(): void
    {
        $this->get(route('apply.create'))
            ->assertOk()
            ->assertSee('Apply for membership')
            ->assertSee('name="website"', false)            // honeypot
            ->assertSee('name="form_token"', false)         // minimum completion time
            ->assertSee('name="_token"', false)             // CSRF
            ->assertSee('data-fee="₱2,000.00"', false)
            ->assertSee('data-validity="12 months"', false)
            ->assertSee('Priority support')
            ->assertSee('Privacy notice');
    }

    public function test_submission_creates_pending_online_member_and_application_and_queues_verification(): void
    {
        Notification::fake();

        $this->post(route('apply.store'), $this->applicationPayload([
            'latitude' => '14.5995', 'longitude' => '120.9842', 'location_consent' => '1',
        ]))->assertRedirect(route('apply.submitted'))->assertSessionHas('application_submitted', true);

        $member = Member::sole();
        $this->assertSame(MemberSource::Online, $member->source);
        $this->assertSame(MemberStatus::Pending, $member->status);
        $this->assertNull($member->created_by);
        $this->assertNull($member->email_verified_at);
        $this->assertNotNull($member->verification_sent_at);
        $this->assertNotNull($member->location_consent_at);
        $this->assertSame('14.5995000', $member->latitude);
        $this->assertMatchesRegularExpression('/^MF-2026-\d{5}$/', $member->membership_no);

        $txn = MembershipTransaction::sole();
        $this->assertSame(TransactionType::Application, $txn->type);
        $this->assertSame(TransactionStatus::Pending, $txn->status);
        $this->assertSame('2000.00', $txn->amount);
        $this->assertSame('0.00', $txn->amount_paid);

        Notification::assertSentTo($member, VerifyApplicantEmail::class);
        Notification::assertNotSentTo($member, MembershipApplicationReceived::class); // the verification email acknowledges it
        $this->assertSame(EmailDeliveryStatus::Queued, EmailDelivery::sole()->status);

        $this->get(route('apply.submitted'))->assertOk()->assertSee('Check your email');
    }

    public function test_zero_fee_level_follows_the_existing_rule_and_starts_paid(): void
    {
        Notification::fake();
        $this->post(route('apply.store'), $this->applicationPayload(['membership_level_id' => $this->free->id]));

        $this->assertSame(TransactionStatus::Paid, MembershipTransaction::sole()->status);
        $this->assertSame(MemberStatus::Pending, Member::sole()->status); // still needs verification + approval
    }

    public function test_public_users_cannot_set_status_numbers_amounts_or_verification(): void
    {
        Notification::fake();

        $this->post(route('apply.store'), $this->applicationPayload([
            'status' => 'active', 'membership_no' => 'MF-HACK', 'email_verified_at' => '2026-01-01 00:00:00',
            'source' => 'walk_in', 'amount' => '1.00', 'amount_paid' => '2000.00', 'created_by' => $this->admin->id,
            'membership_start' => '2026-01-01', 'membership_end' => '2030-01-01', 'public_id' => str_repeat('a', 26),
        ]))->assertRedirect(route('apply.submitted'));

        $member = Member::sole();
        $this->assertSame(MemberStatus::Pending, $member->status);
        $this->assertNotSame('MF-HACK', $member->membership_no);
        $this->assertNull($member->email_verified_at);
        $this->assertSame(MemberSource::Online, $member->source);
        $this->assertNull($member->created_by);
        $this->assertNull($member->membership_end);
        $this->assertNotSame(str_repeat('a', 26), $member->public_id);
        $txn = MembershipTransaction::sole();
        $this->assertSame('2000.00', $txn->amount);
        $this->assertSame('0.00', $txn->amount_paid);
    }

    public function test_public_applicants_get_no_login_or_privileges(): void
    {
        Notification::fake();
        $this->post(route('apply.store'), $this->applicationPayload());

        $this->assertGuest();
        $this->assertSame(1, User::count()); // only the admin fixture
        $this->get(route('admin.members.index'))->assertRedirect(route('login'));
    }

    public function test_duplicate_email_gets_the_identical_response_and_creates_nothing(): void
    {
        Notification::fake();

        $first = $this->post(route('apply.store'), $this->applicationPayload());
        $second = $this->post(route('apply.store'), $this->applicationPayload(['first_name' => 'Other', 'email' => strtoupper(self::email('lea.santos'))]));

        // Same redirect target and same flash data: the browser cannot tell the cases apart.
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $second->assertSessionHas('application_submitted', true);
        $this->assertSame(1, Member::count());
        $this->assertSame('Lea', Member::sole()->first_name);   // existing record untouched
        $this->assertSame(1, MembershipTransaction::count());

        Notification::assertSentOnDemand(ApplicationAlreadyExists::class,
            fn ($n, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === self::email('lea.santos'));
    }

    public function test_duplicate_notice_is_sent_at_most_once_per_day(): void
    {
        Notification::fake();
        $this->post(route('apply.store'), $this->applicationPayload());
        $this->post(route('apply.store'), $this->applicationPayload());
        $this->post(route('apply.store'), $this->applicationPayload());

        $this->assertSame(1, EmailDelivery::where('notification', 'ApplicationAlreadyExists')->count());
        Notification::assertSentOnDemandTimes(ApplicationAlreadyExists::class, 1);
    }

    public function test_duplicate_includes_soft_deleted_members(): void
    {
        Notification::fake();
        $old = Member::factory()->for($this->category, 'category')->for($this->gold, 'level')->create(['email' => self::email('lea.santos')]);
        $old->delete();

        $this->post(route('apply.store'), $this->applicationPayload())->assertRedirect(route('apply.submitted'));
        $this->assertSame(1, Member::withTrashed()->count());
    }

    public function test_required_fields_and_consents_are_validated(): void
    {
        $this->from(route('apply.create'))->post(route('apply.store'), [
            'form_token' => FormTimer::issue(time() - 10),
        ])->assertRedirect(route('apply.create'))
            ->assertSessionHasErrors(['first_name', 'last_name', 'email', 'membership_category_id', 'membership_level_id', 'privacy_consent']);

        $this->post(route('apply.store'), $this->applicationPayload(['email' => 'not-an-email', 'phone' => 'call me', 'birthdate' => '2999-01-01']))
            ->assertSessionHasErrors(['email', 'phone', 'birthdate']);

        // Coordinates need consent; one coordinate alone is rejected.
        $this->post(route('apply.store'), $this->applicationPayload(['latitude' => '14.5', 'longitude' => '120.9']))
            ->assertSessionHasErrors('location_consent');
        $this->post(route('apply.store'), $this->applicationPayload(['latitude' => '14.5', 'location_consent' => '1']))
            ->assertSessionHasErrors('longitude');

        $this->assertSame(0, Member::count());
    }

    public function test_inactive_levels_and_categories_are_rejected(): void
    {
        $this->gold->forceFill(['is_active' => false])->save();
        $this->post(route('apply.store'), $this->applicationPayload())->assertSessionHasErrors('membership_level_id');

        $this->category->forceFill(['is_active' => false])->save();
        $this->post(route('apply.store'), $this->applicationPayload(['membership_level_id' => $this->free->id]))
            ->assertSessionHasErrors('membership_category_id');
        $this->assertSame(0, Member::count());
    }

    public function test_honeypot_submission_looks_successful_but_creates_nothing(): void
    {
        Notification::fake();

        $this->post(route('apply.store'), $this->applicationPayload(['website' => 'http://spam.example']))
            ->assertRedirect(route('apply.submitted'))
            ->assertSessionHas('application_submitted', true);

        $this->assertSame(0, Member::count());
        $this->assertSame(0, EmailDelivery::count());
        Notification::assertNothingSent();
    }

    public function test_minimum_completion_time_and_tampered_or_stale_form_tokens(): void
    {
        $this->post(route('apply.store'), $this->applicationPayload(['form_token' => FormTimer::issue(time())]))
            ->assertSessionHasErrors(['form_token' => 'That was quicker than expected. Please review your details and submit again.']);

        $this->post(route('apply.store'), $this->applicationPayload(['form_token' => 'forged-'.time()]))
            ->assertSessionHasErrors('form_token');

        $this->post(route('apply.store'), $this->applicationPayload(['form_token' => FormTimer::issue(time() - 3 * 3600)]))
            ->assertSessionHasErrors(['form_token' => 'This form has expired. Please review your details and submit again.']);

        $this->post(route('apply.store'), $this->applicationPayload(['form_token' => null]))->assertSessionHasErrors('form_token');
        $this->assertSame(0, Member::count());
    }

    public function test_submissions_are_rate_limited_per_ip(): void
    {
        Notification::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('apply.store'), $this->applicationPayload(['email' => self::email("rate{$i}")]))
                ->assertRedirect(route('apply.submitted'));
        }

        $this->post(route('apply.store'), $this->applicationPayload(['email' => self::email('rate5')]))->assertStatus(429);
        $this->assertSame(5, Member::count());
    }

    public function test_submitted_page_cannot_be_opened_directly_and_carries_no_identifiers(): void
    {
        $this->get(route('apply.submitted'))->assertRedirect(route('apply.create'));
        $this->assertSame(url('/apply/submitted'), route('apply.submitted')); // no id/reference in the URL
    }

    public function test_form_works_without_maps_or_weather_keys(): void
    {
        config(['services.google_maps.key' => null, 'services.openweather.key' => null]);

        $this->get(route('apply.create'))->assertOk();
        Notification::fake();
        $this->post(route('apply.store'), $this->applicationPayload())->assertRedirect(route('apply.submitted'));
        $this->assertSame(1, Member::count());
    }

    public function test_failure_inside_the_transaction_queues_no_email(): void
    {
        Notification::fake();

        // A category that vanished between validation and insert → FK failure → full rollback.
        try {
            app(PublicApplicationService::class)->submit(
                array_merge($this->applicationPayload(), ['membership_category_id' => 999999]), false);
            $this->fail('Expected the insert to fail.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(0, Member::count());
        $this->assertSame(0, EmailDelivery::count());
        Notification::assertNothingSent();
    }

    public function test_walk_in_registration_sends_application_received_not_verification(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)->post(route('admin.members.store'), [
            'first_name' => 'Walk', 'last_name' => 'In', 'email' => self::email('walk.in'),
            'membership_category_id' => $this->category->id, 'membership_level_id' => $this->gold->id,
            'create_application' => '1',
        ])->assertRedirect();

        $member = Member::sole();
        Notification::assertSentTo($member, MembershipApplicationReceived::class);
        Notification::assertNotSentTo($member, VerifyApplicantEmail::class);
    }
}
