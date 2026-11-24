<?php

namespace Tests\Feature\Email;

use App\Enums\EmailDeliveryStatus;
use App\Enums\TransactionStatus;
use App\Models\EmailDelivery;
use App\Models\Member;
use App\Models\MembershipTransaction;
use App\Models\Task;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\TestEmail;
use App\Services\ApplicantVerificationService;
use App\Services\EmailDeliveryTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * QUEUE_CONNECTION=sync (local demo without `php artisan queue:work`).
 *
 * Emails are sent in the same request, only after the DB transaction commits; a send
 * failure is recorded as "failed" and never turns a successful action into an error page.
 * Nothing here contacts a real mail server: the "array" mailer and a failing fake transport are used.
 */
class SyncQueueModeTest extends EmailTestCase
{
    private function failingSmtp(): void
    {
        Mail::extend('failing-smtp', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Connection could not be established with host "smtp.gmail.com:587": timed out');
            }

            public function __toString(): string
            {
                return 'failing-smtp://';
            }
        });
        config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'failing-smtp', 'username' => 'x@gmail.com', 'password' => 'secret-app-password']]);
        app('mail.manager')->purge('smtp');
    }

    public function test_the_test_environment_really_uses_the_sync_driver(): void
    {
        $this->assertTrue(app(EmailDeliveryTracker::class)->usesSyncQueue());
        config(['queue.default' => 'database']);
        $this->assertFalse(app(EmailDeliveryTracker::class)->usesSyncQueue());
    }

    public function test_email_is_sent_immediately_without_a_worker_and_tracked_honestly(): void
    {
        $delivery = app(EmailDeliveryTracker::class)->send($this->admin, new TestEmail, $this->admin);

        $delivery->refresh();
        $this->assertSame(EmailDeliveryStatus::Logged, $delivery->status);   // array mailer = not delivered
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->sent_at);
        $this->assertSame(0, DB::table('jobs')->count());                     // nothing waits for a worker
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_nothing_is_sent_before_the_transaction_commits_or_after_a_rollback(): void
    {
        Notification::fake();

        DB::transaction(function () {
            app(EmailDeliveryTracker::class)->send($this->admin, new TestEmail, $this->admin);
            Notification::assertNothingSent();                    // still inside the transaction
        });
        Notification::assertSentTo($this->admin, TestEmail::class);   // sent right after commit

        Notification::fake();
        try {
            DB::transaction(function () {
                app(EmailDeliveryTracker::class)->send($this->admin, new TestEmail, $this->admin);
                throw new \RuntimeException('business rule failed');
            });
        } catch (\RuntimeException) {
        }
        Notification::assertNothingSent();
        $this->assertSame(1, EmailDelivery::count());              // the rolled-back row is gone too
    }

    public function test_smtp_failure_does_not_break_or_undo_the_business_action(): void
    {
        $this->failingSmtp();

        // Invitation: the page succeeds, the invitation exists, the email is recorded as failed.
        $invitee = Member::factory()->for($this->category, 'category')->for($this->gold, 'level')->create(['email' => self::email('fail.safe')]);
        $this->actingAs($this->admin)->post(route('admin.members.account-invitation', $invitee))
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame(1, UserInvitation::count());

        // Walk-in registration + approval of a paid application: both succeed.
        $this->actingAs($this->admin)->post(route('admin.members.store'), [
            'first_name' => 'Sync', 'last_name' => 'Safe', 'email' => self::email('sync.safe'),
            'membership_category_id' => $this->category->id, 'membership_level_id' => $this->gold->id, 'create_application' => '1',
        ])->assertRedirect()->assertSessionHas('success');
        $txn = MembershipTransaction::sole();
        $txn->forceFill(['amount_paid' => $txn->amount, 'status' => TransactionStatus::Paid])->save();
        $this->actingAs($this->admin)->patch(route('admin.transactions.approve', $txn))->assertSessionHas('success');
        $this->assertSame(TransactionStatus::Approved, $txn->fresh()->status);
        $this->assertSame('active', Member::where('email', self::email('sync.safe'))->sole()->status->value);

        // Task assignment: one task, not a duplicate after the failed email.
        $staff = User::factory()->member()->create(['email' => self::email('staff.sync')]);
        $this->actingAs($this->admin)->post(route('admin.tasks.store'), ['title' => 'Sync task', 'priority' => 'medium', 'assigned_to' => $staff->id])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame(1, Task::count());

        // Every email: exactly one row each, recorded as failed with a safe message (no credentials).
        $rows = EmailDelivery::orderBy('id')->get();
        $this->assertSame(['MemberAccountInvitation', 'MembershipApplicationReceived', 'MembershipApproved', 'TaskAssignedNotification'], $rows->pluck('notification')->all());
        foreach ($rows as $row) {
            $this->assertSame(EmailDeliveryStatus::Failed, $row->status, $row->notification);
            $this->assertSame(1, $row->attempts);
            $this->assertStringContainsString('Connection could not be established', $row->error_message);
            $this->assertStringNotContainsString('secret-app-password', $row->error_message);
        }
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_failed_sync_email_can_be_retried_from_the_email_log(): void
    {
        $this->failingSmtp();
        $member = Member::factory()->for($this->category, 'category')->for($this->gold, 'level')->create(['email' => self::email('retry.sync')]);
        app(ApplicantVerificationService::class)->sendVerification($member, respectCooldown: false);
        $failed = EmailDelivery::sole();
        $this->assertSame(EmailDeliveryStatus::Failed, $failed->status);

        // Mail server is back: the administrator retries from the Email log.
        config(['mail.default' => 'array']);
        $this->actingAs($this->admin)->post(route('admin.email-deliveries.retry', $failed))->assertSessionHas('success');

        $retry = EmailDelivery::whereKeyNot($failed->id)->sole();
        $this->assertSame($retry->id, $failed->fresh()->superseded_by_id);
        $this->assertSame(EmailDeliveryStatus::Logged, $retry->status);   // delivered to the array mailer
    }

    public function test_public_application_and_invitation_links_work_in_sync_mode(): void
    {
        config(['app.url' => 'http://192.168.100.4:8000']);

        // Public application: verification email with LAN links, sent at once.
        $this->post('http://127.0.0.1:8000/apply', $this->applicationPayload())->assertRedirect(route('apply.submitted'));
        $html = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('http://192.168.100.4:8000/apply/verify/', $html);
        $this->assertStringContainsString('http://192.168.100.4:8000/apply/status/', $html);
        $this->assertStringNotContainsString('127.0.0.1', $html);
        $this->assertSame(EmailDeliveryStatus::Logged, EmailDelivery::where('notification', 'VerifyApplicantEmail')->sole()->status);

        // Member activation invitation: single-use link that opens the activation page.
        $this->inviteMember(self::email('sync.invitee'), 'Sync Invitee');
        $html = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHtmlBody();
        $this->assertMatchesRegularExpression('#http://192\.168\.100\.4:8000/invitations/[A-Za-z0-9]{64}#', $html);
        preg_match('#/invitations/[A-Za-z0-9]{64}#', $html, $m);
        auth()->logout();
        $this->get($m[0])->assertOk()->assertSee('Activate your account');
    }

    public function test_database_queue_mode_is_unchanged(): void
    {
        config(['queue.default' => 'database']);
        $this->inviteMember(self::email('queued.invitee'), 'Queued Invitee');

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(EmailDeliveryStatus::Queued, EmailDelivery::sole()->status);
        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();
        $this->assertSame(EmailDeliveryStatus::Logged, EmailDelivery::sole()->status);
    }
}
