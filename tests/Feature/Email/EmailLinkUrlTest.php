<?php

namespace Tests\Feature\Email;

use App\Events\StaffInvitationIssued;
use App\Models\Member;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\ApplicationAlreadyExists;
use App\Notifications\MemberAccountInvitation;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskReassignedAway;
use App\Notifications\TaskStatusNotification;
use App\Services\ApplicantVerificationService;
use App\Services\InvitationService;
use App\Services\TaskService;
use App\Support\AppUrl;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Regression: emailed links must use the configured APP_URL, never the host of the
 * current request or the URL root a long-running process captured at start-up.
 *
 * In tests the URL generator root is fixed at boot (APP_URL in .env.testing =
 * http://127.0.0.1:8000). Changing config('app.url') afterwards reproduces the bug:
 * Laravel's absolute route() kept producing 127.0.0.1 links.
 */
class EmailLinkUrlTest extends EmailTestCase
{
    private const LAN = 'http://192.168.100.4:8000';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => self::LAN]);
    }

    private function sentInvitationUrl(): string
    {
        $url = null;
        Notification::assertSentOnDemand(MemberAccountInvitation::class, function (MemberAccountInvitation $n, $channels, AnonymousNotifiable $to) use (&$url) {
            $url = $n->toMail($to)->actionUrl;

            return true;
        });

        return $url;
    }

    public function test_url_generator_root_differs_from_app_url_in_this_scenario(): void
    {
        // Precondition proving the test reproduces the bug conditions.
        $this->assertStringStartsWith('http://127.0.0.1:8000', route('invitation.show', str_repeat('a', 64)));
        $this->assertStringStartsWith(self::LAN, AppUrl::route('invitation.show', str_repeat('a', 64)));
    }

    public function test_invitation_email_link_uses_the_configured_app_url_and_works(): void
    {
        Notification::fake();
        $this->inviteMember(self::email('lan.invitee'), 'Lan Invitee');

        $url = $this->sentInvitationUrl();

        $this->assertMatchesRegularExpression('#^http://192\.168\.100\.4:8000/invitations/[A-Za-z0-9]{64}$#', $url);
        $this->assertStringNotContainsString('127.0.0.1', $url);
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertSee('Activate your account');
    }

    public function test_invitation_link_ignores_the_host_the_admin_used(): void
    {
        Notification::fake();

        // The administrator works on the PC (127.0.0.1); the invitee will open the link on a phone.
        $member = Member::factory()->for($this->category, 'category')->for($this->gold, 'level')->create(['email' => self::email('phone.invitee')]);
        $this->actingAs($this->admin)
            ->post("http://127.0.0.1:8000/admin/members/{$member->id}/account-invitation")
            ->assertRedirect();

        $this->assertStringStartsWith(self::LAN.'/invitations/', $this->sentInvitationUrl());
    }

    public function test_resent_link_event_url_and_dev_command_use_the_configured_app_url(): void
    {
        Notification::fake();
        Event::listen(StaffInvitationIssued::class, function (StaffInvitationIssued $event) {
            $this->assertStringStartsWith(self::LAN.'/invitations/', $event->activationUrl());
        });

        $this->inviteMember(self::email('resend.invitee'), 'Resend Invitee');
        $this->travel(2)->minutes();
        app(InvitationService::class)->resend(UserInvitation::sole(), $this->admin);

        Notification::assertSentOnDemand(MemberAccountInvitation::class, fn (MemberAccountInvitation $n, $c, $to) => $n->isResend
            && str_starts_with($n->toMail($to)->actionUrl, self::LAN.'/invitations/'));

        $this->travel(2)->minutes();
        $this->artisan('memberflow:invitation-link', ['email' => self::email('resend.invitee')])
            ->expectsOutputToContain(self::LAN.'/invitations/')
            ->assertSuccessful();
    }

    public function test_link_built_by_the_queued_job_uses_app_url(): void
    {
        // Real queued notification, processed by the worker, delivered to the in-memory "array" mailer.
        config(['queue.default' => 'database']);
        $this->inviteMember(self::email('queued.invitee'), 'Queued Invitee');

        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $body = $messages->first()->getOriginalMessage()->getHtmlBody();
        $this->assertMatchesRegularExpression('#'.preg_quote(self::LAN, '#').'/invitations/[A-Za-z0-9]{64}#', $body);
        $this->assertStringNotContainsString('127.0.0.1', $body);
    }

    public function test_trailing_slash_in_app_url_does_not_double_the_slash(): void
    {
        config(['app.url' => self::LAN.'/']);

        $this->assertSame(self::LAN.'/invitations/'.str_repeat('b', 64), AppUrl::route('invitation.show', str_repeat('b', 64)));
        $this->assertSame(self::LAN.'/apply?x=1', AppUrl::to('/apply?x=1'));
    }

    public function test_every_other_emailed_link_uses_the_configured_app_url(): void
    {
        Notification::fake();
        $staff = User::factory()->member()->create(['email' => self::email('staff.links')]);
        $other = User::factory()->member()->create(['email' => self::email('other.links')]);
        $tasks = app(TaskService::class);

        $task = $tasks->create(['title' => 'Link check'], $staff, $this->admin);
        $tasks->reassign($task, $other, $this->admin);
        $tasks->recordProgress($task, $other, ['status' => 'completed']);

        Notification::assertSentTo($other, TaskAssignedNotification::class, fn ($n) => str_starts_with($n->toMail($other)->actionUrl, self::LAN.'/my/tasks/'));
        Notification::assertSentTo($staff, TaskReassignedAway::class, fn ($n) => str_starts_with($n->toMail($staff)->actionUrl, self::LAN.'/my/tasks/'));
        Notification::assertSentTo($this->admin, TaskStatusNotification::class, fn ($n) => str_starts_with($n->toMail($this->admin)->actionUrl, self::LAN.'/admin/tasks/'));

        $to = Notification::route('mail', self::email('dup'));
        $this->assertStringStartsWith(self::LAN.'/apply/verification/resend', (new ApplicationAlreadyExists)->toMail($to)->actionUrl);

        $member = Member::factory()->for($this->category, 'category')->for($this->gold, 'level')->create();
        $this->assertStringStartsWith(self::LAN.'/apply/verify/', app(ApplicantVerificationService::class)->verificationUrl($member));
        $this->assertStringStartsWith(self::LAN.'/apply/status/', app(ApplicantVerificationService::class)->statusUrl($member));
    }
}
