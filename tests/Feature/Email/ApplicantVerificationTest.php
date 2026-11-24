<?php

namespace Tests\Feature\Email;

use App\Enums\MemberSource;
use App\Enums\MemberStatus;
use App\Enums\TransactionStatus;
use App\Models\EmailDelivery;
use App\Models\Member;
use App\Models\MembershipTransaction;
use App\Models\User;
use App\Notifications\VerifyApplicantEmail;
use App\Services\ApplicantVerificationService;
use App\Services\MembershipService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;

class ApplicantVerificationTest extends EmailTestCase
{
    private ApplicantVerificationService $verification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verification = app(ApplicantVerificationService::class);
    }

    private function onlineApplicant(array $overrides = []): Member
    {
        Notification::fake();
        $this->post(route('apply.store'), $this->applicationPayload($overrides))->assertRedirect(route('apply.submitted'));

        return Member::latest('id')->firstOrFail();
    }

    /** Path + query of an absolute URL (what a browser requests). */
    private function path(string $url): string
    {
        $parts = parse_url($url);

        return $parts['path'].'?'.$parts['query'];
    }

    public function test_verification_email_contains_signed_relative_links_bound_to_the_member(): void
    {
        $member = $this->onlineApplicant();

        Notification::assertSentTo($member, VerifyApplicantEmail::class, function (VerifyApplicantEmail $n) use ($member) {
            $mail = $n->toMail($member);
            $this->assertStringStartsWith(config('app.url').'/apply/verify/'.$member->public_id.'/', $mail->actionUrl);
            $this->assertStringContainsString('signature=', $mail->actionUrl);
            $this->assertStringContainsString('expires=', $mail->actionUrl);
            $this->assertStringNotContainsString('/'.$member->id.'/', $mail->actionUrl); // never the numeric id
            $this->assertStringContainsString('48 hours', implode(' ', array_merge($mail->introLines, $mail->outroLines)));

            return true;
        });
    }

    public function test_valid_link_verifies_email_without_paying_or_approving_anything(): void
    {
        $member = $this->onlineApplicant();
        $txn = MembershipTransaction::sole();

        $this->get($this->path($this->verification->verificationUrl($member)))
            ->assertOk()
            ->assertSee('Your email is verified')
            ->assertSee('does <strong>not</strong> approve', false)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $member->refresh();
        $this->assertNotNull($member->email_verified_at);
        $this->assertSame(MemberStatus::Pending, $member->status);
        $this->assertNull($member->membership_start);

        $txn->refresh();
        $this->assertSame(TransactionStatus::Pending, $txn->status);
        $this->assertSame('0.00', $txn->amount_paid);
        $this->assertNull($txn->approved_at);
    }

    public function test_verifying_twice_is_idempotent(): void
    {
        $member = $this->onlineApplicant();
        $url = $this->path($this->verification->verificationUrl($member));

        $this->get($url)->assertOk();
        $first = $member->fresh()->email_verified_at;
        $this->travel(5)->minutes();

        $this->get($url)->assertOk()->assertSee('already verified');
        $this->assertEquals($first, $member->fresh()->email_verified_at);
    }

    public function test_link_works_on_any_host_because_the_signature_is_relative(): void
    {
        $member = $this->onlineApplicant();

        $this->get('http://192.168.1.20:8000'.$this->path($this->verification->verificationUrl($member)))->assertOk();
        $this->assertNotNull($member->fresh()->email_verified_at);
    }

    public function test_tampered_links_show_the_invalid_page(): void
    {
        $member = $this->onlineApplicant();
        $url = $this->path($this->verification->verificationUrl($member));

        $this->get(preg_replace('/signature=([0-9a-f])/', 'signature=0$1', $url))->assertStatus(403)->assertSee('not valid');
        $this->get(str_replace('expires=', 'expires=9', $url))->assertStatus(403);
        $this->get(strtok($url, '?'))->assertStatus(403);                       // signature removed

        $other = $this->onlineApplicant(['email' => self::email('other')]);
        $swapped = str_replace($member->public_id, $other->public_id, $url);   // valid signature, other member
        $this->get($swapped)->assertStatus(403);

        $this->get('/apply/verify/'.$member->id.'/'.sha1($member->email))->assertNotFound(); // numeric ids never match

        $this->assertNull($member->fresh()->email_verified_at);
        $this->assertNull($other->fresh()->email_verified_at);
    }

    public function test_links_still_work_when_the_host_appends_its_i_parameter(): void
    {
        // InfinityFree's browser check reloads a visitor's first request with "i=1" appended.
        $member = $this->onlineApplicant();
        $status = $this->path($this->verification->statusUrl($member));
        $verify = $this->path($this->verification->verificationUrl($member));

        $this->get($status.'&i=1')->assertOk();
        $this->get($verify.'&i=1')->assertOk()->assertSee('Your email is verified');
        $this->assertNotNull($member->fresh()->email_verified_at);
    }

    public function test_ignoring_the_host_parameter_does_not_weaken_the_signature(): void
    {
        $member = $this->onlineApplicant();
        $url = $this->path($this->verification->verificationUrl($member));

        $this->get(preg_replace('/signature=([0-9a-f])/', 'signature=0$1', $url).'&i=1')->assertStatus(403);
        $this->get(str_replace('expires=', 'expires=9', $url).'&i=1')->assertStatus(403);
        $this->get($url.'&j=1')->assertStatus(403);                            // any other extra parameter still breaks it
        $this->assertNull($member->fresh()->email_verified_at);
    }

    public function test_expired_link_shows_the_expired_page(): void
    {
        $member = $this->onlineApplicant();
        $url = $this->path($this->verification->verificationUrl($member));

        $this->travel(49)->hours();

        $this->get($url)->assertStatus(410)->assertSee('has expired')->assertSee(route('apply.resend'));
        $this->assertNull($member->fresh()->email_verified_at);
    }

    public function test_changing_the_email_invalidates_old_links_and_resets_verification(): void
    {
        $member = $this->onlineApplicant();
        $oldUrl = $this->path($this->verification->verificationUrl($member));

        app(MembershipService::class)->update($member, ['email' => self::email('new.address')]);

        $this->get($oldUrl)->assertStatus(403);
        $this->assertNull($member->fresh()->email_verified_at);
    }

    public function test_status_page_shows_only_minimal_information(): void
    {
        $member = $this->onlineApplicant(['latitude' => '14.5995', 'longitude' => '120.9842', 'location_consent' => '1']);
        $txn = MembershipTransaction::sole();

        $this->get($this->path($this->verification->statusUrl($member)))
            ->assertOk()
            ->assertSee('Hello, Lea')
            ->assertSee($txn->reference_no)
            ->assertSee('Pending')
            ->assertSee('Not verified yet')
            ->assertSee('₱2,000.00')
            ->assertSee('₱0.00')
            ->assertDontSee('Santos')                // last name
            ->assertDontSee('0917 555 0101')         // phone
            ->assertDontSee('Mabini')                // address
            ->assertDontSee('14.5995')               // coordinates
            ->assertDontSee('1995')                  // birthdate
            ->assertDontSee($member->email)
            ->assertDontSee($member->membership_no)
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_status_link_requires_a_valid_unexpired_signature(): void
    {
        $member = $this->onlineApplicant();
        $url = $this->path($this->verification->statusUrl($member));

        $this->get(strtok($url, '?'))->assertStatus(403);
        $this->travel(31)->days();
        $this->get($url)->assertStatus(410);
    }

    public function test_resend_gives_one_generic_response_and_only_sends_to_unverified_online_applicants(): void
    {
        $online = $this->onlineApplicant();
        $verified = $this->onlineApplicant(['email' => self::email('verified')]);
        $this->verification->verify($verified);
        $walkIn = Member::factory()->for($this->category, 'category')->for($this->gold, 'level')->create(['email' => self::email('walkin')]);
        $inactive = $this->onlineApplicant(['email' => self::email('inactive')]);
        $inactive->forceFill(['status' => MemberStatus::Inactive])->save();
        $this->travel(2)->minutes(); // past the resend cooldown

        Notification::fake();
        $this->withoutMiddleware(ThrottleRequests::class); // per-IP limit tested separately
        $messages = [];
        foreach ([$online->email, $verified->email, $walkIn->email, $inactive->email, self::email('nobody')] as $email) {
            $messages[] = $this->post(route('apply.resend.send'), ['email' => $email])
                ->assertRedirect(route('apply.resend'))
                ->getSession()->get('status');
        }

        $this->assertCount(1, array_unique($messages));                // identical for every address
        Notification::assertSentToTimes($online, VerifyApplicantEmail::class, 1);
        Notification::assertNotSentTo($verified, VerifyApplicantEmail::class);
        Notification::assertNotSentTo($walkIn, VerifyApplicantEmail::class);
        Notification::assertNotSentTo($inactive, VerifyApplicantEmail::class);
    }

    public function test_resend_respects_the_cooldown_and_the_per_ip_limit(): void
    {
        $member = $this->onlineApplicant();

        Notification::fake();
        $this->post(route('apply.resend.send'), ['email' => $member->email])->assertRedirect();
        Notification::assertNothingSent();                              // sent < 60s ago on submission

        $this->travel(2)->minutes();
        $this->post(route('apply.resend.send'), ['email' => $member->email]);
        $this->post(route('apply.resend.send'), ['email' => $member->email]);
        Notification::assertSentToTimes($member, VerifyApplicantEmail::class, 1);

        $this->post(route('apply.resend.send'), ['email' => $member->email])->assertStatus(429);
    }

    public function test_resend_honeypot_and_validation(): void
    {
        $member = $this->onlineApplicant();
        $this->travel(2)->minutes();
        Notification::fake();

        $this->post(route('apply.resend.send'), ['email' => $member->email, 'website' => 'x'])->assertRedirect(route('apply.resend'));
        Notification::assertNothingSent();

        $this->post(route('apply.resend.send'), ['email' => 'nope'])->assertSessionHasErrors('email');
        $this->get(route('apply.resend'))->assertOk()->assertSee('Resend the verification email');
    }

    // ---- Approval rule (enforced in MembershipService) ---------------------

    public function test_fully_paid_online_application_cannot_be_approved_until_verified(): void
    {
        $member = $this->onlineApplicant();
        $txn = MembershipTransaction::sole();
        $txn->forceFill(['amount_paid' => $txn->amount, 'status' => TransactionStatus::Paid])->save(); // controlled fixture

        $this->actingAs($this->admin)->get(route('admin.transactions.show', $txn))
            ->assertSee('has not verified their email')
            ->assertSee('Resend verification email');

        $this->actingAs($this->admin)->patch(route('admin.transactions.approve', $txn))->assertSessionHas('error');
        $this->assertSame(TransactionStatus::Paid, $txn->fresh()->status);

        $this->verification->verify($member);

        $this->actingAs($this->admin)->patch(route('admin.transactions.approve', $txn))->assertSessionHas('success');
        $this->assertSame(TransactionStatus::Approved, $txn->fresh()->status);
        $this->assertSame(MemberStatus::Active, $member->fresh()->status);
    }

    public function test_walk_in_members_are_exempt_from_verification(): void
    {
        Notification::fake();
        $member = app(MembershipService::class)->register([
            'first_name' => 'Walk', 'last_name' => 'In', 'email' => self::email('walk'),
            'membership_category_id' => $this->category->id, 'membership_level_id' => $this->gold->id,
        ], $this->admin);
        $txn = MembershipTransaction::sole();
        $txn->forceFill(['amount_paid' => $txn->amount, 'status' => TransactionStatus::Paid])->save();

        $this->assertSame(MemberSource::WalkIn, $member->source);
        app(MembershipService::class)->approve($txn, $this->admin);
        $this->assertSame(TransactionStatus::Approved, $txn->fresh()->status);
    }

    // ---- Admin resend --------------------------------------------------------

    public function test_admin_can_send_a_verification_email_from_the_member_profile(): void
    {
        $member = $this->onlineApplicant();
        $this->travel(2)->minutes();
        Notification::fake();

        $this->actingAs($this->admin)->get(route('admin.members.show', $member))
            ->assertSee('Email verification')->assertSee('Send verification email');

        $this->actingAs($this->admin)->post(route('admin.members.send-verification', $member))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'queued') && str_contains($m, 'NOT delivered'));
        Notification::assertSentTo($member, VerifyApplicantEmail::class);

        // Cooldown
        $this->actingAs($this->admin)->post(route('admin.members.send-verification', $member))->assertSessionHas('error');

        $staff = User::factory()->member()->create();
        $this->actingAs($staff)->post(route('admin.members.send-verification', $member))->assertForbidden();
        $this->assertSame(2, EmailDelivery::where('notification', 'VerifyApplicantEmail')->count());
    }

    public function test_dev_command_prints_links_only_in_local_or_testing(): void
    {
        $member = $this->onlineApplicant();

        $this->artisan('memberflow:verification-link', ['email' => $member->email])
            ->expectsOutputToContain('/apply/verify/'.$member->public_id)
            ->expectsOutputToContain('/apply/status/'.$member->public_id)
            ->assertSuccessful();

        $this->artisan('memberflow:verification-link', ['email' => self::email('unknown')])->assertFailed();
    }
}
