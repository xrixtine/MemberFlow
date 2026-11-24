<?php

namespace Tests\Feature\Email;

use App\Console\Commands\ServeCommand;
use App\Enums\EmailDeliveryStatus;
use App\Exceptions\EmailDeliveryException;
use App\Models\EmailDelivery;
use App\Models\Member;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\MemberAccountInvitation;
use App\Notifications\TestEmail;
use App\Notifications\VerifyApplicantEmail;
use App\Services\EmailDeliveryTracker;
use App\Services\SecureLinkRedactor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class EmailDeliveryTrackingTest extends EmailTestCase
{
    private EmailDeliveryTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = app(EmailDeliveryTracker::class);
    }

    /** Make "smtp" fail like an unreachable / rejecting SMTP server, without any network. */
    private function failingSmtp(string $message): void
    {
        Mail::extend('failing-smtp', fn () => new class($message) extends AbstractTransport
        {
            public function __construct(private string $failure)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                throw new TransportException($this->failure);
            }

            public function __toString(): string
            {
                return 'failing-smtp://';
            }
        });

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => ['transport' => 'failing-smtp', 'username' => 'sender@gmail.com', 'password' => 'abcd efgh ijkl mnop'],
        ]);
        app('mail.manager')->purge('smtp');
    }

    private function member(string $local = 'member'): Member
    {
        return Member::factory()->for($this->category, 'category')->for($this->gold, 'level')->create(['email' => self::email($local)]);
    }

    // ---- Statuses -------------------------------------------------------------

    public function test_dispatch_creates_a_queued_row_with_the_configured_mailer(): void
    {
        Notification::fake();

        $delivery = $this->tracker->send($this->admin, new TestEmail, $this->admin);

        $this->assertSame(EmailDeliveryStatus::Queued, $delivery->status);
        $this->assertSame('array', $delivery->mailer);
        $this->assertSame(0, $delivery->attempts);
        $this->assertNotNull($delivery->queued_at);
        $this->assertSame('TestEmail', $delivery->notification);
        $this->assertSame('user', $delivery->notifiable_type);
        Notification::assertSentTo($this->admin, TestEmail::class, fn (TestEmail $n) => $n->deliveryId === $delivery->id);
    }

    public function test_successful_send_through_a_non_smtp_mailer_is_logged_never_sent(): void
    {
        $delivery = $this->tracker->send($this->admin, new TestEmail, $this->admin); // sync queue + array mailer

        $delivery->refresh();
        $this->assertSame(EmailDeliveryStatus::Logged, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->sent_at);
        $this->assertNotNull($delivery->message_id);
    }

    public function test_successful_smtp_hand_off_is_sent_accepted_by_smtp(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'array']]); // accepts without network
        app('mail.manager')->purge('smtp');

        $delivery = $this->tracker->send($this->admin, new TestEmail, $this->admin)->refresh();

        $this->assertSame(EmailDeliveryStatus::Sent, $delivery->status);
        $this->assertSame('smtp', $delivery->mailer);
        $this->assertSame('Accepted by SMTP server', $delivery->status->label());
        $this->assertStringContainsString('does not guarantee', $delivery->status->description());
    }

    public function test_smtp_failure_is_failed_with_a_safe_message_and_never_logged(): void
    {
        $this->failingSmtp('Failed to authenticate on SMTP server with username "sender@gmail.com" using password "abcd efgh ijkl mnop": 535 5.7.8 Username and Password not accepted');

        // Sync mode (tests use QUEUE_CONNECTION=sync): the failure is recorded, never thrown into
        // the caller. With the database queue the worker retries and then calls failed().
        $returned = $this->tracker->send($this->admin, new TestEmail, $this->admin);

        $delivery = EmailDelivery::sole();
        $this->assertTrue($returned->is($delivery));
        $this->assertSame(EmailDeliveryStatus::Failed, $delivery->status);
        $this->assertSame('smtp', $delivery->mailer);
        $this->assertNotNull($delivery->failed_at);
        $this->assertNull($delivery->sent_at);
        $this->assertStringContainsString('Username and Password not accepted', $delivery->error_message);
        $this->assertStringNotContainsString('abcd efgh ijkl mnop', $delivery->error_message);
        $this->assertStringNotContainsString('sender@gmail.com', $delivery->error_message);
        $this->assertSame(1, EmailDelivery::count()); // no silent second "log" delivery
    }

    public function test_fictional_recipients_are_skipped_and_nothing_is_dispatched(): void
    {
        Notification::fake();

        foreach (['someone@example.test', 'a@example.com', 'b@mail.example.org', 'c@foo.invalid', 'd@host.localhost', 'e@demo.example'] as $email) {
            $delivery = $this->tracker->send(Notification::route('mail', $email), new TestEmail);
            $this->assertSame(EmailDeliveryStatus::Skipped, $delivery->status, $email);
            $this->assertNull($delivery->queued_at);
        }

        Notification::assertNothingSent();
        $this->assertFalse($this->tracker->isFictional('real.person@gmail.com'));
        $this->assertFalse($this->tracker->isFictional('x@'.self::REAL_DOMAIN));
        $this->assertFalse($this->tracker->isFictional('x@notexample.com'));
    }

    public function test_dedupe_key_prevents_duplicate_emails(): void
    {
        Notification::fake();
        $member = $this->member();

        $a = $this->tracker->send($member, new VerifyApplicantEmail($member), $member, 'once:member:'.$member->id);
        $b = $this->tracker->send($member, new VerifyApplicantEmail($member), $member, 'once:member:'.$member->id);

        $this->assertTrue($a->is($b));
        $this->assertSame(1, EmailDelivery::count());
        Notification::assertSentToTimes($member, VerifyApplicantEmail::class, 1);
    }

    public function test_a_final_or_superseded_delivery_is_never_sent_again(): void
    {
        $delivery = EmailDelivery::create([
            'notification' => 'TestEmail', 'recipient_email' => $this->admin->email, 'mailer' => 'smtp',
            'status' => EmailDeliveryStatus::Sent, 'queued_at' => now(),
        ]);
        $this->assertFalse($this->tracker->shouldSend($delivery->id));

        $delivery->forceFill(['status' => EmailDeliveryStatus::Failed])->save();
        $this->assertTrue($this->tracker->shouldSend($delivery->id));   // queue:retry of a failed job may send

        $delivery->forceFill(['superseded_by_id' => $delivery->id])->save();
        $this->assertFalse($this->tracker->shouldSend($delivery->id));  // replaced by an admin retry
    }

    public function test_mark_failed_never_downgrades_a_successful_delivery(): void
    {
        $delivery = EmailDelivery::create([
            'notification' => 'TestEmail', 'recipient_email' => $this->admin->email, 'mailer' => 'smtp',
            'status' => EmailDeliveryStatus::Sent, 'queued_at' => now(), 'sent_at' => now(),
        ]);

        $this->tracker->markFailed($delivery->id, new \RuntimeException('late'));
        $this->assertSame(EmailDeliveryStatus::Sent, $delivery->fresh()->status);
    }

    // ---- Queue processing (database queue) ------------------------------------

    public function test_database_queue_dispatches_after_commit_and_worker_updates_the_status(): void
    {
        config(['queue.default' => 'database']);

        $delivery = $this->tracker->send($this->admin, new TestEmail, $this->admin);

        $this->assertSame(1, \DB::table('jobs')->count());
        $this->assertSame(EmailDeliveryStatus::Queued, $delivery->fresh()->status);

        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

        $this->assertSame(0, \DB::table('jobs')->count());
        $this->assertSame(EmailDeliveryStatus::Logged, $delivery->fresh()->status); // array mailer ⇒ not delivered
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_queued_invitation_job_is_encrypted_so_the_token_is_not_stored_in_plain_text(): void
    {
        config(['queue.default' => 'database']);

        [, $token] = $this->inviteMember(self::email('queue.tester'), 'Queue Tester');

        $payload = (string) \DB::table('jobs')->value('payload');
        $this->assertNotSame('', $payload);
        $this->assertStringNotContainsString($token, $payload);
        $this->assertStringNotContainsString('MemberAccountInvitation', json_decode($payload, true)['data']['command']); // encrypted command
        $this->assertStringNotContainsString($token, (string) \DB::table('email_deliveries')->get()->toJson());

        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();
        $this->assertSame(EmailDeliveryStatus::Logged, EmailDelivery::sole()->status);
    }

    public function test_failed_job_marks_failed_and_lands_in_failed_jobs(): void
    {
        config(['queue.default' => 'database']);
        $this->failingSmtp('Connection could not be established with host "smtp.gmail.com:587"');

        $notification = new TestEmail;
        $notification->tries = 1;
        $delivery = $this->tracker->send($this->admin, $notification, $this->admin);

        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true, '--tries' => 1]);

        $delivery->refresh();
        $this->assertSame(EmailDeliveryStatus::Failed, $delivery->status);
        $this->assertStringContainsString('Connection could not be established', $delivery->error_message);
        $this->assertSame(1, \DB::table('failed_jobs')->count());
    }

    // ---- Log mode ---------------------------------------------------------------

    public function test_log_mode_writes_mail_to_the_mail_log_with_secure_links_removed(): void
    {
        $path = storage_path('logs/mail-test-'.uniqid().'.log');
        config(['mail.default' => 'log', 'logging.channels.mail.path' => $path]);

        try {
            [, $token] = $this->inviteMember(self::email('log.tester'), 'Log Tester');

            $logged = File::get($path);
            $this->assertStringContainsString('Activate my member account', $logged);
            $this->assertStringContainsString('secure link removed', $logged);
            $this->assertStringNotContainsString($token, $logged);
            $this->assertSame(EmailDeliveryStatus::Logged, EmailDelivery::sole()->status);
        } finally {
            File::delete($path);
        }
    }

    public function test_application_logs_redact_tokens_and_signatures(): void
    {
        $path = storage_path('logs/app-test-'.uniqid().'.log');
        config(['logging.channels.single.path' => $path]);
        $token = str_repeat('Ab3', 21).'x';

        try {
            Log::channel('single')->warning('Visited http://127.0.0.1:8000/invitations/'.$token, ['url' => '/apply/verify/abc/def?expires=1&signature='.str_repeat('f', 64)]);
            $content = File::get($path);
            $this->assertStringNotContainsString($token, $content);
            $this->assertStringNotContainsString(str_repeat('f', 64), $content);
            $this->assertStringContainsString(SecureLinkRedactor::PLACEHOLDER, $content);
        } finally {
            File::delete($path);
        }
    }

    public function test_redactor_and_serve_command(): void
    {
        $token = str_repeat('Zz9', 21).'q';
        $line = "[Thu Nov 12 10:00:00 2026] 127.0.0.1:50000 [200]: GET /invitations/{$token}";
        $this->assertStringNotContainsString($token, SecureLinkRedactor::redact($line));
        $this->assertStringContainsString('/apply/status/', SecureLinkRedactor::redact('/apply/status/x/y'));  // unsigned paths untouched
        $this->assertStringNotContainsString('deadbeef', SecureLinkRedactor::redact('GET /apply/verify/a/b?expires=1&signature=deadbeefdeadbeefdeadbeef'));

        $this->assertInstanceOf(ServeCommand::class, \Artisan::all()['serve']);
    }

    // ---- Admin interface -----------------------------------------------------------

    public function test_email_log_is_admin_only(): void
    {
        $this->get(route('admin.email-deliveries.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->member()->create())->get(route('admin.email-deliveries.index'))->assertForbidden();
        $this->actingAs(User::factory()->member()->create())->post(route('admin.email-deliveries.test'))->assertForbidden();
    }

    public function test_email_log_lists_deliveries_with_filters_and_log_mode_warning(): void
    {
        Notification::fake();
        $member = $this->member('listed');
        $this->tracker->send($member, new VerifyApplicantEmail($member), $member);
        $this->tracker->send(Notification::route('mail', 'ghost@example.test'), new TestEmail);

        $this->actingAs($this->admin)->get(route('admin.email-deliveries.index'))
            ->assertOk()
            ->assertSee('Email log')
            ->assertSee($member->email)
            ->assertSee('Applicant email verification')
            ->assertSee($member->membership_no)
            ->assertSee('Skipped (fictional recipient)')
            ->assertSee('NOT delivered');                          // array mailer ⇒ local/log mode warning

        $this->actingAs($this->admin)->get(route('admin.email-deliveries.index', ['status' => 'skipped']))
            ->assertSee('ghost@example.test')->assertDontSee($member->email);
        $this->actingAs($this->admin)->get(route('admin.email-deliveries.index', ['q' => 'listed']))
            ->assertSee($member->email)->assertDontSee('ghost@example.test');
    }

    public function test_log_mode_banner_and_missing_credentials_banner(): void
    {
        config(['mail.default' => 'log']);
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee('Email is in local log mode');

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.username' => null, 'mail.mailers.smtp.password' => null]);
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee('Email is misconfigured');

        config(['mail.mailers.smtp.username' => 'x@gmail.com', 'mail.mailers.smtp.password' => 'secret-value']);
        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertDontSee('Email is in local log mode')->assertDontSee('misconfigured')->assertDontSee('secret-value');

        $staff = User::factory()->member()->create();
        config(['mail.default' => 'log']);
        $this->actingAs($staff)->get(route('dashboard'))->assertDontSee('Email is in local log mode');
    }

    public function test_send_test_email_goes_only_to_the_signed_in_admin(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)->post(route('admin.email-deliveries.test'), ['email' => 'attacker@'.self::REAL_DOMAIN])
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'queued for '.$this->admin->email));

        Notification::assertSentTo($this->admin, TestEmail::class);
        $this->assertSame($this->admin->email, EmailDelivery::sole()->recipient_email);

        $fictional = User::factory()->admin()->create(['email' => 'boss@example.test']);
        $this->actingAs($fictional)->post(route('admin.email-deliveries.test'))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'skipped') && str_contains($m, 'real inbox'));
    }

    public function test_dashboard_shows_failed_and_stale_email_indicators(): void
    {
        EmailDelivery::create(['notification' => 'TestEmail', 'recipient_email' => 'a@'.self::REAL_DOMAIN, 'mailer' => 'smtp',
            'status' => EmailDeliveryStatus::Failed, 'queued_at' => now(), 'failed_at' => now(), 'error_message' => 'boom']);
        EmailDelivery::create(['notification' => 'TestEmail', 'recipient_email' => 'b@'.self::REAL_DOMAIN, 'mailer' => 'smtp',
            'status' => EmailDeliveryStatus::Queued, 'queued_at' => now()->subMinutes(10)]);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertSee('1 email delivery failed')
            ->assertSee('queued for over 5 minutes');

        $this->actingAs($this->admin)->get(route('admin.email-deliveries.index', ['stale' => 1]))
            ->assertSee('b@'.self::REAL_DOMAIN)->assertDontSee('a@'.self::REAL_DOMAIN)
            ->assertSee('worker not running');
    }

    // ---- Retry --------------------------------------------------------------------

    public function test_failed_delivery_can_be_retried_once_and_supersedes_the_old_row(): void
    {
        $member = $this->member('retry');
        $old = EmailDelivery::create([
            'notification' => 'VerifyApplicantEmail', 'dedupe_key' => 'k:1', 'recipient_email' => $member->email,
            'notifiable_type' => 'member', 'notifiable_id' => $member->id, 'related_type' => 'member', 'related_id' => $member->id,
            'mailer' => 'smtp', 'status' => EmailDeliveryStatus::Failed, 'queued_at' => now(), 'failed_at' => now(), 'attempts' => 3,
        ]);

        Notification::fake();
        $this->actingAs($this->admin)->post(route('admin.email-deliveries.retry', $old))->assertSessionHas('success');

        $old->refresh();
        $new = EmailDelivery::whereKeyNot($old->id)->sole();
        $this->assertSame($new->id, $old->superseded_by_id);
        $this->assertNull($old->dedupe_key);
        $this->assertSame('k:1', $new->dedupe_key);
        $this->assertSame(EmailDeliveryStatus::Queued, $new->status);
        Notification::assertSentTo($member, VerifyApplicantEmail::class);

        $this->actingAs($this->admin)->post(route('admin.email-deliveries.retry', $old))->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('admin.email-deliveries.retry', $new))->assertSessionHas('error'); // not failed
    }

    public function test_failed_invitation_cannot_be_retried_because_the_token_is_not_stored(): void
    {
        Notification::fake();
        $this->inviteMember(self::email('retry.invitee'), 'Retry Invitee');
        $delivery = EmailDelivery::sole();
        $delivery->forceFill(['status' => EmailDeliveryStatus::Failed, 'failed_at' => now()])->save();

        $this->expectException(EmailDeliveryException::class);
        $this->expectExceptionMessage('Resend');
        $this->tracker->retry($delivery);
    }

    public function test_tracked_rows_never_contain_tokens_or_links(): void
    {
        Notification::fake();
        [, $token] = $this->inviteMember(self::email('row.check'), 'Row Check');

        $row = EmailDelivery::sole();
        $this->assertSame('invitation', $row->related_type);
        $this->assertSame(UserInvitation::sole()->id, $row->related_id);
        $this->assertStringNotContainsString($token, json_encode($row->getAttributes()));
        Notification::assertSentOnDemand(MemberAccountInvitation::class);
    }
}
