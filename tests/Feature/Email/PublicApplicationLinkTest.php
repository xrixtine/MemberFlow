<?php

namespace Tests\Feature\Email;

use App\Enums\EmailDeliveryStatus;
use App\Models\EmailDelivery;
use App\Models\Member;
use App\Notifications\TestEmail;
use App\Notifications\VerifyApplicantEmail;
use App\Services\EmailDeliveryTracker;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Regression (Nov 22): public-application emails contained http://127.0.0.1:8000 links
 * although APP_URL was the LAN address, because a queue worker started before the
 * APP_URL change built them. Emails now carry the APP_URL captured when they were
 * queued, and every link is APP_URL + relative path.
 *
 * In tests the URL generator root stays http://127.0.0.1:8000 (from .env.testing), so a
 * different config('app.url') reproduces "configured LAN URL vs. stale localhost root".
 */
class PublicApplicationLinkTest extends EmailTestCase
{
    private const LAN = 'http://192.168.100.4:8000';

    private const LOCAL = 'http://127.0.0.1:8000';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => self::LAN]);
    }

    /** All text of the verification email sent to $member, with its action URL. */
    private function verificationMail(Member $member): array
    {
        $mail = null;
        Notification::assertSentTo($member, VerifyApplicantEmail::class, function (VerifyApplicantEmail $n) use ($member, &$mail) {
            $mail = $n->toMail($member);

            return true;
        });

        return [$mail->actionUrl, implode("\n", array_merge($mail->introLines, $mail->outroLines))];
    }

    private function pathAndQuery(string $url): string
    {
        return parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    }

    public function test_applicant_on_another_host_gets_verification_and_status_links_on_app_url(): void
    {
        Notification::fake();

        // The applicant fills in the form on the PC (127.0.0.1); the email must still point to the LAN URL.
        $this->post(self::LOCAL.'/apply', $this->applicationPayload())->assertRedirect();
        $member = Member::sole();

        [$verifyUrl, $text] = $this->verificationMail($member);

        $this->assertStringStartsWith(self::LAN.'/apply/verify/'.$member->public_id.'/', $verifyUrl);
        $this->assertMatchesRegularExpression('#'.preg_quote(self::LAN, '#').'/apply/status/'.$member->public_id.'/[0-9a-f]{40}\?expires=\d+&signature=[0-9a-f]{64}#', $text);
        $this->assertStringNotContainsString('127.0.0.1', $verifyUrl.$text);
    }

    public function test_links_open_through_the_lan_host_and_signatures_are_still_enforced(): void
    {
        Notification::fake();
        $this->post(self::LOCAL.'/apply', $this->applicationPayload());
        $member = Member::sole();
        [$verifyUrl, $text] = $this->verificationMail($member);
        preg_match('#'.preg_quote(self::LAN, '#').'(/apply/status/\S+)#', $text, $status);

        // Opened on another device on the LAN (Host: 192.168.100.4:8000).
        $this->get(self::LAN.$this->pathAndQuery($verifyUrl))->assertOk()->assertSee('Your email is verified');
        $this->assertNotNull($member->fresh()->email_verified_at);
        $this->get(self::LAN.$status[1])->assertOk()->assertSee('Hello, Lea');

        // Signature, expiry and email binding are unchanged.
        $this->get(self::LAN.preg_replace('/signature=([0-9a-f])/', 'signature=0$1', $status[1]))->assertStatus(403);
        $this->travel(31)->days();
        $this->get(self::LAN.$status[1])->assertStatus(410);
    }

    public function test_admin_resend_from_another_host_uses_app_url(): void
    {
        Notification::fake();
        $this->post(self::LOCAL.'/apply', $this->applicationPayload());
        $member = Member::sole();
        $this->travel(2)->minutes();

        Notification::fake();
        $this->actingAs($this->admin)->post(self::LOCAL.'/admin/members/'.$member->id.'/send-verification')->assertSessionHasNoErrors();

        [$verifyUrl, $text] = $this->verificationMail($member);
        $this->assertStringStartsWith(self::LAN.'/apply/verify/', $verifyUrl);
        $this->assertStringContainsString(self::LAN.'/apply/status/', $text);
    }

    public function test_queued_email_keeps_the_app_url_from_when_it_was_queued_even_if_the_worker_is_stale(): void
    {
        config(['queue.default' => 'database']);

        // The web request queues the email while APP_URL is the LAN address…
        $this->post(self::LOCAL.'/apply', $this->applicationPayload())->assertRedirect();
        $this->assertSame(1, \DB::table('jobs')->count());

        // …but the worker still has the old localhost APP_URL in memory.
        config(['app.url' => self::LOCAL]);
        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

        $html = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString(self::LAN.'/apply/verify/', $html);
        $this->assertStringContainsString(self::LAN.'/apply/status/', $html);
        $this->assertStringNotContainsString('127.0.0.1', $html);          // incl. the mail layout header link
        $this->assertSame(EmailDeliveryStatus::Logged, EmailDelivery::sole()->status);
        $this->assertSame(self::LOCAL, config('app.url'));                  // the worker's own config is restored afterwards
    }

    public function test_one_application_sends_one_email_containing_both_links(): void
    {
        Notification::fake();
        $this->post(route('apply.store'), $this->applicationPayload());

        // By design: the verification email also carries the private status link and
        // acknowledges the application; there is no separate "received" or "status" email.
        $this->assertSame(['VerifyApplicantEmail'], EmailDelivery::pluck('notification')->all());
        Notification::assertSentTimes(VerifyApplicantEmail::class, 1);
    }

    // ---- Email log status while sending / retrying ---------------------------------------

    private function badge(EmailDelivery $delivery): string
    {
        return trim(strip_tags(Blade::render('<x-delivery-status :delivery="$d"/>', ['d' => $delivery])));
    }

    public function test_in_progress_send_is_not_labelled_as_a_retry(): void
    {
        $delivery = EmailDelivery::create(['notification' => 'VerifyApplicantEmail', 'recipient_email' => self::email('x'), 'mailer' => 'smtp',
            'status' => EmailDeliveryStatus::Queued, 'attempts' => 1, 'queued_at' => now(), 'last_attempt_at' => now()]);

        $this->assertSame('Sending — attempt in progress', $this->badge($delivery));
    }

    public function test_a_failed_attempt_is_recorded_safely_and_labelled_as_retrying(): void
    {
        config(['mail.mailers.smtp.username' => 'sender@gmail.com', 'mail.mailers.smtp.password' => 'abcd efgh ijkl mnop']);
        $notification = new TestEmail;
        $delivery = EmailDelivery::create(['notification' => 'TestEmail', 'recipient_email' => $this->admin->email, 'mailer' => 'smtp',
            'status' => EmailDeliveryStatus::Queued, 'attempts' => 1, 'queued_at' => now(), 'last_attempt_at' => now()]);
        $notification->deliveryId = $delivery->id;

        event(new NotificationFailed($this->admin, $notification, 'mail', [
            'exception' => new \RuntimeException('Connection could not be established with host "smtp.gmail.com:587" for sender@gmail.com / abcd efgh ijkl mnop'),
        ]));

        $delivery->refresh();
        $this->assertSame(EmailDeliveryStatus::Queued, $delivery->status);          // still retrying, not failed
        $this->assertStringContainsString('Connection could not be established', $delivery->error_message);
        $this->assertStringNotContainsString('abcd efgh ijkl mnop', $delivery->error_message);
        $this->assertStringNotContainsString('sender@gmail.com', $delivery->error_message);
        $this->assertSame('Queued — retrying after an error', $this->badge($delivery));

        // A later successful attempt clears the error.
        app(EmailDeliveryTracker::class)->markSent($delivery->id, null, 'smtp');
        $this->assertNull($delivery->fresh()->error_message);
        $this->assertSame(EmailDeliveryStatus::Sent, $delivery->fresh()->status);
    }
}
