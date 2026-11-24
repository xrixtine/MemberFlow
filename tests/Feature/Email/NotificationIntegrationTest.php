<?php

namespace Tests\Feature\Email;

use App\Enums\EmailDeliveryStatus;
use App\Enums\TaskPriority;
use App\Enums\TransactionStatus;
use App\Events\TransactionApproved;
use App\Models\EmailDelivery;
use App\Models\Member;
use App\Models\MembershipTransaction;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\MemberAccountInvitation;
use App\Notifications\MembershipApplicationReceived;
use App\Notifications\MembershipApproved;
use App\Notifications\MembershipRenewalSubmitted;
use App\Notifications\MembershipTransactionCancelled;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskReassignedAway;
use App\Notifications\TaskStatusNotification;
use App\Services\MembershipService;
use App\Services\TaskService;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class NotificationIntegrationTest extends EmailTestCase
{
    // ---- Member-account activation invitations (staff invitations retired in R6) ----

    private function invitationUrl(): string
    {
        $url = null;
        Notification::assertSentOnDemand(MemberAccountInvitation::class, function (MemberAccountInvitation $n, $channels, AnonymousNotifiable $to) use (&$url) {
            $url = $n->toMail($to)->actionUrl;

            return true;
        });

        return $url;
    }

    public function test_invitation_email_is_queued_with_a_working_single_use_activation_link(): void
    {
        Notification::fake();

        $member = Member::factory()->for($this->category, 'category')->for($this->gold, 'level')
            ->create(['first_name' => 'Nina', 'middle_name' => null, 'last_name' => 'Member', 'email' => self::email('nina.member')]);
        $this->actingAs($this->admin)->post(route('admin.members.account-invitation', $member))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'queued for '.self::email('nina.member')));

        $invitation = UserInvitation::sole();
        $delivery = EmailDelivery::sole();
        $this->assertSame('MemberAccountInvitation', $delivery->notification);
        $this->assertSame('invitation', $delivery->related_type);
        $this->assertSame(EmailDeliveryStatus::Queued, $delivery->status);

        Notification::assertSentOnDemand(MemberAccountInvitation::class, function (MemberAccountInvitation $n, $channels, AnonymousNotifiable $to) use ($invitation) {
            $mail = $n->toMail($to);
            $this->assertSame(self::email('nina.member'), $to->routes['mail']);
            $this->assertStringContainsString('/invitations/', $mail->actionUrl);
            $this->assertStringContainsString('expires on', implode(' ', array_merge($mail->introLines, $mail->outroLines)));
            $this->assertStringContainsString('choose a password', implode(' ', $mail->introLines));

            return $n->invitation->is($invitation);
        });

        $url = $this->invitationUrl();
        $this->assertStringNotContainsString(basename($url), $this->actingAs($this->admin)->get(route('admin.invitations.show', $invitation))->getContent());

        auth()->logout();
        $this->get($url)->assertOk()->assertSee('Activate your account');
        $this->post($url, ['password' => 'Activate123', 'password_confirmation' => 'Activate123'])
            ->assertRedirect(route('dashboard'));
        $this->assertSame('member', User::where('email', self::email('nina.member'))->firstOrFail()->role->value);

        auth()->logout();
        $this->get($url)->assertStatus(410);   // single use
    }

    public function test_resend_emails_a_new_link_and_the_old_link_stops_working(): void
    {
        Notification::fake();
        $this->inviteMember(self::email('rita'), 'Rita Resend');
        $oldUrl = $this->invitationUrl();

        Notification::fake();
        $this->travel(2)->minutes();
        $this->actingAs($this->admin)->post(route('admin.invitations.resend', UserInvitation::sole()))->assertSessionHas('success');
        $newUrl = $this->invitationUrl();

        Notification::assertSentOnDemand(MemberAccountInvitation::class, fn (MemberAccountInvitation $n) => $n->isResend === true);
        $this->assertNotSame($oldUrl, $newUrl);
        $this->assertSame(2, EmailDelivery::where('notification', 'MemberAccountInvitation')->count());

        auth()->logout();
        $this->get($oldUrl)->assertNotFound();
        $this->get($newUrl)->assertOk();
    }

    public function test_invitation_page_shows_honest_delivery_history(): void
    {
        [$invitation] = $this->inviteMember(self::email('log.mode'), 'Log Mode');

        // Sync queue + array mailer in tests ⇒ processed immediately and "logged", never "sent".
        $this->actingAs($this->admin)->get(route('admin.invitations.show', $invitation))
            ->assertSee('Invitation emails')
            ->assertSee('Local log only — NOT delivered')
            ->assertDontSee('Accepted by SMTP server</span>', false);
    }

    // ---- Membership notifications ---------------------------------------------

    private function walkInMember(string $local = 'walk.in'): Member
    {
        return app(MembershipService::class)->register([
            'first_name' => 'Walter', 'last_name' => 'Inn', 'email' => self::email($local),
            'membership_category_id' => $this->category->id, 'membership_level_id' => $this->gold->id,
        ], $this->admin);
    }

    private function pay(MembershipTransaction $txn): MembershipTransaction
    {
        $txn->forceFill(['amount_paid' => $txn->amount, 'status' => TransactionStatus::Paid])->save(); // controlled fixture

        return $txn->fresh();
    }

    public function test_membership_lifecycle_notifications_are_sent_once_each(): void
    {
        Notification::fake();
        $service = app(MembershipService::class);

        $member = $this->walkInMember();
        Notification::assertSentToTimes($member, MembershipApplicationReceived::class, 1);

        $txn = $this->pay(MembershipTransaction::sole());
        $service->approve($txn, $this->admin);
        Notification::assertSentToTimes($member, MembershipApproved::class, 1);

        // Re-dispatching the same business event never emails twice (dedupe per transaction).
        TransactionApproved::dispatch($txn->fresh());
        Notification::assertSentToTimes($member, MembershipApproved::class, 1);

        // Renewal inside the 60-day window
        $this->travelTo(now()->addMonths(11));
        $renewal = $service->openRenewal($member->fresh(), $this->gold, $this->admin);
        Notification::assertSentToTimes($member, MembershipRenewalSubmitted::class, 1);

        $service->cancel($renewal, $this->admin, 'Applied by mistake');
        Notification::assertSentTo($member, MembershipTransactionCancelled::class, function (MembershipTransactionCancelled $n) use ($member) {
            $text = implode(' ', $n->toMail($member)->introLines);

            return ! str_contains($text, 'Applied by mistake'); // internal reason is not emailed
        });

        $this->assertSame(4, EmailDelivery::count());
        $this->assertSame(4, EmailDelivery::whereNotNull('dedupe_key')->count());
    }

    public function test_rolled_back_transactions_send_nothing(): void
    {
        Notification::fake();

        try {
            DB::transaction(function () {
                $this->walkInMember('rollback');
                throw new \RuntimeException('abort after registration');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, Member::withTrashed()->count());
        $this->assertSame(0, EmailDelivery::count());
        Notification::assertNothingSent();
    }

    public function test_approval_email_contains_the_validity_period(): void
    {
        Notification::fake();
        $member = $this->walkInMember();
        app(MembershipService::class)->approve($this->pay(MembershipTransaction::sole()), $this->admin);

        Notification::assertSentTo($member, MembershipApproved::class, function (MembershipApproved $n) use ($member) {
            $text = implode(' ', $n->toMail($member)->introLines);

            return str_contains($text, 'November 12, 2026 to November 11, 2027') && str_contains($text, $member->membership_no);
        });
    }

    public function test_fictional_members_are_recorded_as_skipped(): void
    {
        Notification::fake();
        $member = Member::factory()->for($this->category, 'category')->for($this->gold, 'level')->create(); // @example.test
        app(MembershipService::class)->openApplication($member, $this->admin);

        $this->assertSame(EmailDeliveryStatus::Skipped, EmailDelivery::sole()->status);
        Notification::assertNothingSent();
    }

    // ---- Task notifications ----------------------------------------------------

    private function staff(string $name, string $local): User
    {
        return User::factory()->member()->create(['name' => $name, 'email' => self::email($local)]);
    }

    public function test_task_assignment_and_reassignment_notify_the_right_people(): void
    {
        Notification::fake();
        $ana = $this->staff('Ana Staff', 'ana');
        $ben = $this->staff('Ben Staff', 'ben');
        $tasks = app(TaskService::class);

        $task = $tasks->create(['title' => 'Call new applicants', 'priority' => TaskPriority::High->value, 'due_date' => '2026-11-20'], $ana, $this->admin);
        Notification::assertSentTo($ana, TaskAssignedNotification::class, function (TaskAssignedNotification $n) use ($ana) {
            $mail = $n->toMail($ana);

            return ! $n->isReassignment
                && str_contains($mail->subject, 'New task assigned')
                && str_contains($mail->actionUrl, '/my/tasks/');
        });

        $tasks->reassign($task, $ben, $this->admin);
        Notification::assertSentTo($ben, TaskAssignedNotification::class, fn (TaskAssignedNotification $n) => $n->isReassignment);
        Notification::assertSentTo($ana, TaskReassignedAway::class);
        Notification::assertNotSentTo($this->admin, TaskAssignedNotification::class);

        $this->assertSame(3, EmailDelivery::count());
    }

    public function test_only_completion_and_on_hold_notify_the_creating_admin(): void
    {
        Notification::fake();
        $ana = $this->staff('Ana Staff', 'ana2');
        $otherAdmin = User::factory()->admin()->create(['email' => self::email('other.admin')]);
        $tasks = app(TaskService::class);
        $task = $tasks->create(['title' => 'Prepare renewal list'], $ana, $this->admin);

        $tasks->recordProgress($task, $ana, ['status' => 'in_progress', 'progress' => 30]);
        $tasks->recordProgress($task, $ana, ['note' => 'Halfway through the list']);
        Notification::assertNotSentTo($this->admin, TaskStatusNotification::class);   // no spam for routine updates

        $tasks->recordProgress($task, $ana, ['status' => 'on_hold', 'note' => 'Waiting for the printer']);
        Notification::assertSentToTimes($this->admin, TaskStatusNotification::class, 1);

        $tasks->recordProgress($task, $ana, ['status' => 'completed']);
        Notification::assertSentToTimes($this->admin, TaskStatusNotification::class, 2);
        Notification::assertNotSentTo($otherAdmin, TaskStatusNotification::class);    // only the task's creator
        Notification::assertNotSentTo($ana, TaskStatusNotification::class);
    }

    public function test_when_the_creator_is_inactive_all_active_admins_are_notified(): void
    {
        Notification::fake();
        $ana = $this->staff('Ana Staff', 'ana3');
        $otherAdmin = User::factory()->admin()->create(['email' => self::email('backup.admin')]);
        $inactiveAdmin = User::factory()->admin()->inactive()->create(['email' => self::email('gone.admin')]);
        $tasks = app(TaskService::class);
        $task = $tasks->create(['title' => 'Archive old forms'], $ana, $this->admin);

        $this->admin->forceFill(['is_active' => false])->save();
        $tasks->recordProgress($task, $ana, ['status' => 'completed']);

        Notification::assertSentTo($otherAdmin, TaskStatusNotification::class);
        Notification::assertNotSentTo($this->admin, TaskStatusNotification::class);
        Notification::assertNotSentTo($inactiveAdmin, TaskStatusNotification::class);
    }
}
