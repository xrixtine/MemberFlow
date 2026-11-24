<?php

namespace Tests\Feature\Members;

use App\Enums\MemberStatus;
use App\Enums\TaskStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\EmailDelivery;
use App\Models\Member;
use App\Models\MembershipLevel;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Services\MemberPortalService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase R5 — member dashboard and self-service portal: calculations, renewal eligibility,
 * payments (voids excluded), task read-only rules, notifications, printable record, isolation.
 */
class MemberPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 20)->setTime(9, 0));
        $this->admin = User::factory()->admin()->create();
    }

    private function account(array $memberAttributes = []): User
    {
        $level = MembershipLevel::factory()->create(['name' => 'Gold '.uniqid(), 'benefits' => "Free parking\nEvent discounts"]);
        $member = Member::factory()->active()->for($level, 'level')->create(['middle_name' => null] + $memberAttributes);
        $member->forceFill(['membership_no' => 'MF-2026-'.random_int(10000, 99999)])->save();

        return User::factory()->member($member)->create();
    }

    private function transaction(User $user, array $attributes): MembershipTransaction
    {
        $txn = MembershipTransaction::factory()->create(['member_id' => $user->member_id, 'membership_level_id' => $user->member->membership_level_id] + $attributes);
        $txn->forceFill(['reference_no' => 'TXN-'.strtoupper(uniqid())])->save();

        return $txn;
    }

    private function pay(MembershipTransaction $txn, string $amount, string $receipt, bool $voided = false): Payment
    {
        $payment = Payment::factory()->for($txn, 'transaction')->create(['amount' => $amount, 'paid_at' => now()->subDay()]);
        $payment->forceFill(['receipt_no' => $receipt] + ($voided ? ['voided_at' => now(), 'voided_by' => $this->admin->id, 'void_reason' => 'Entered twice'] : []))->save();

        return $payment;
    }

    private function delivery(string $notification, string $notifiableType, int $notifiableId, string $status = 'sent', ?string $error = null): void
    {
        EmailDelivery::forceCreate([
            'notification' => $notification, 'recipient_email' => 'someone@inbox.memberflow.dev', 'notifiable_type' => $notifiableType,
            'notifiable_id' => $notifiableId, 'mailer' => 'smtp', 'status' => $status, 'error_message' => $error, 'queued_at' => now(),
        ]);
    }

    // ---- Dashboard calculations --------------------------------------------------------

    public function test_dashboard_shows_validity_days_remaining_balance_and_task_statistics(): void
    {
        $user = $this->account(['first_name' => 'Dina', 'membership_start' => '2026-01-01', 'membership_end' => '2026-12-31']);
        $renewal = $this->transaction($user, ['type' => TransactionType::Renewal, 'amount' => '1500.00', 'amount_paid' => '500.00']);
        $this->pay($renewal, '500.00', 'OR-2026-500001');
        $done = Task::factory()->create(['assigned_to' => $user->id, 'status' => TaskStatus::Completed, 'progress' => 100, 'completed_at' => now()]);
        Task::factory()->create(['assigned_to' => $user->id, 'status' => TaskStatus::InProgress, 'progress' => 40, 'due_date' => today()->subDay(), 'title' => 'Late task']);
        Task::factory()->create(['assigned_to' => $user->id, 'due_date' => today()->addDays(2), 'title' => 'Soon task']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('Welcome, Dina.')->assertSee($user->member->membership_no)
            ->assertSee('Valid until Dec 31, 2026')
            ->assertSeeInOrder(['Days remaining', '41'])              // Nov 20 → Dec 31
            ->assertSee('₱1,000.00')                                  // balance due
            ->assertSee('Your renewal is waiting for payment')
            ->assertSee('Renewal:')->assertSee('You already have an application or renewal in progress')
            ->assertSee('1 overdue')->assertSee('Late task')->assertSee('Soon task')
            ->assertSee('Payment received')->assertSee('Renewal submitted');

        $summary = app(MemberPortalService::class)->taskSummary($user);
        $this->assertSame([3, 2, 1, 1, 33], [$summary['total'], $summary['open'], $summary['completed'], $summary['overdue'], $summary['completion_rate']]);
        $this->assertSame($done->id, Task::where('status', 'completed')->value('id'));
    }

    public function test_dashboard_for_a_pending_member_with_nothing_yet(): void
    {
        $user = $this->account(['status' => MemberStatus::Pending, 'membership_start' => null, 'membership_end' => null]);
        $user->member->forceFill(['membership_no' => null])->save();

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('membership number pending')->assertSee('Not yet active')
            ->assertSee('No open tasks')->assertSee('No notifications yet')
            ->assertSee('Renewal becomes available after your first application is approved.');
    }

    // ---- Renewal eligibility ------------------------------------------------------------------

    public function test_renewal_eligibility_follows_the_administrator_rules(): void
    {
        $portal = app(MemberPortalService::class);

        $early = $this->account(['membership_end' => today()->addDays(120)]);
        $r = $portal->renewal($early->member);
        $this->assertFalse($r['eligible']);
        $this->assertStringContainsString('Renewal opens 60 days before your membership ends, on '.today()->addDays(60)->format('F j, Y'), $r['message']);

        $inWindow = $this->account(['membership_end' => today()->addDays(30)]);
        $this->assertTrue($portal->renewal($inWindow->member)['eligible']);

        $expired = $this->account(['status' => MemberStatus::Expired, 'membership_end' => today()->subDays(5)]);
        $r = $portal->renewal($expired->member);
        $this->assertTrue($r['eligible']);
        $this->assertStringContainsString('has expired', $r['message']);

        $this->transaction($inWindow, ['type' => TransactionType::Renewal]);
        $this->assertFalse($portal->renewal($inWindow->member->fresh())['eligible']);

        $this->actingAs($early)->get(route('my.membership'))->assertOk()->assertSee('Not available yet')->assertSee('Renewals are set up by the membership office');
        $this->actingAs($expired)->get(route('my.membership'))->assertOk()->assertSee('Available now');
    }

    public function test_membership_page_shows_profile_verification_and_history(): void
    {
        $user = $this->account(['first_name' => 'Vera', 'phone' => '09175550000']);
        $user->member->forceFill(['email_verified_at' => now()])->save();
        $approved = $this->transaction($user, ['amount' => '2000.00', 'amount_paid' => '2000.00', 'status' => TransactionStatus::Approved,
            'approved_at' => now()->subMonth(), 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']);

        $this->actingAs($user)->get(route('my.membership'))->assertOk()
            ->assertSee('Vera')->assertSee($user->member->membership_no)->assertSee('09175550000')
            ->assertSee('Email verified')->assertSee('Free parking')->assertSee('Event discounts')
            ->assertSee($approved->reference_no)->assertSee('Your latest application was approved')
            ->assertSee('Print my record');
    }

    // ---- Payments ------------------------------------------------------------------------------

    public function test_payment_totals_exclude_voided_payments_and_cancelled_charges(): void
    {
        $user = $this->account();
        $app = $this->transaction($user, ['amount' => '2000.00', 'amount_paid' => '2000.00', 'status' => TransactionStatus::Approved]);
        $this->pay($app, '1500.00', 'OR-2026-000101');
        $this->pay($app, '500.00', 'OR-2026-000102');
        $this->pay($app, '700.00', 'OR-2026-000103', voided: true);
        $this->transaction($user, ['amount' => '900.00', 'status' => TransactionStatus::Cancelled]);
        $open = $this->transaction($user, ['type' => TransactionType::Renewal, 'amount' => '1200.00', 'amount_paid' => '200.00']);
        $this->pay($open, '200.00', 'OR-2026-000104');

        $s = app(MemberPortalService::class)->paymentSummary($user->member);
        $this->assertSame(['₱3,200.00', '₱2,200.00', '₱1,000.00', 3, 1], [$s['total_charges'], $s['total_paid'], $s['outstanding'], $s['count'], $s['voided_count']]);

        $this->actingAs($user)->get(route('my.payments.index'))->assertOk()
            ->assertSee('₱3,200.00')->assertSee('₱2,200.00')->assertSee('₱1,000.00')
            ->assertSee('OR-2026-000103')->assertSee('Void')->assertSee('not counted in your totals')
            ->assertDontSee('Record payment')->assertDontSee('Void payment');
    }

    public function test_members_cannot_record_void_or_approve_anything(): void
    {
        $user = $this->account();
        $txn = $this->transaction($user, ['amount' => '1000.00', 'amount_paid' => '1000.00', 'status' => TransactionStatus::Paid]);
        $payment = $this->pay($txn, '1000.00', 'OR-2026-000200');
        $this->actingAs($user);

        $this->post(route('admin.payments.store'), ['membership_transaction_id' => $txn->id, 'amount' => '10.00'])->assertForbidden();
        $this->patch(route('admin.payments.void', $payment), ['void_reason' => 'x'])->assertForbidden();
        $this->patch(route('admin.transactions.approve', $txn))->assertForbidden();
        $this->post(route('admin.transactions.store'), ['member_id' => $user->member_id, 'type' => 'renewal'])->assertForbidden();
        $this->patch(route('admin.members.update', $user->member), ['status' => 'active'])->assertForbidden();
        $this->get(route('admin.reports.index'))->assertForbidden();

        $this->assertSame(TransactionStatus::Paid, $txn->fresh()->status);
        $this->assertNull($payment->fresh()->voided_at);
        $this->assertSame(1, MembershipTransaction::count());
    }

    // ---- Tasks after expiry / pending ---------------------------------------------------------

    public function test_expired_and_pending_members_keep_read_only_task_access(): void
    {
        foreach ([MemberStatus::Expired->value => today()->subDays(3), MemberStatus::Pending->value => null] as $status => $end) {
            $user = $this->account();
            $task = app(TaskService::class)->create(['title' => "Task for {$status}", 'priority' => 'low'], $user, $this->admin);
            $user->member->forceFill(['status' => MemberStatus::from($status), 'membership_end' => $end])->save();
            $this->actingAs($user->fresh());

            $this->get(route('my.tasks.index'))->assertOk()->assertSee('Your tasks are read-only')->assertSee("Task for {$status}");
            $this->get(route('my.tasks.show', $task))->assertOk()->assertSee('Your membership is not currently active')->assertDontSee('Save update');
            $this->post(route('my.tasks.progress', $task), ['status' => 'in_progress', 'progress' => 30])->assertForbidden();
            $this->assertSame(0, $task->fresh()->progress);
            $this->get(route('my.history'))->assertOk();
        }
    }

    public function test_admin_is_prompted_to_reassign_an_unfinished_task_from_an_ineligible_member(): void
    {
        $user = $this->account();
        $other = $this->account();
        $task = app(TaskService::class)->create(['title' => 'Unfinished', 'priority' => 'low'], $user, $this->admin);
        $user->member->forceFill(['status' => MemberStatus::Expired, 'membership_end' => today()->subDay()])->save();

        $this->actingAs($this->admin)->get(route('admin.tasks.show', $task))->assertOk()
            ->assertSee('The assignee can no longer work on this task.')->assertSee('Reassign it to an eligible member');
        $this->patch(route('admin.tasks.reassign', $task), ['assigned_to' => $other->id])->assertSessionHas('success');
        $this->assertSame($other->id, $task->fresh()->assigned_to);
        $this->assertSame(1, TaskAssignment::where('task_id', $task->id)->where('user_id', $user->id)->count()); // history kept
    }

    public function test_active_member_still_updates_their_tasks(): void
    {
        $user = $this->account();
        $task = app(TaskService::class)->create(['title' => 'Active work', 'priority' => 'low'], $user, $this->admin);

        $this->actingAs($user)->get(route('my.tasks.index'))->assertDontSee('Your tasks are read-only');
        $this->post(route('my.tasks.progress', $task), ['status' => 'in_progress', 'progress' => 30])->assertRedirect();
        $this->assertSame(30, $task->fresh()->progress);
    }

    // ---- Notifications -------------------------------------------------------------------------

    public function test_notifications_show_only_this_members_emails_without_internal_details(): void
    {
        $user = $this->account();
        $other = $this->account();
        $this->delivery('MembershipApproved', 'member', $user->member_id);
        $this->delivery('TaskAssignedNotification', 'user', $user->id, 'failed', 'SMTP 535 Username and Password not accepted secret-hint');
        $this->delivery('TestEmail', 'user', $user->id);                              // admin-only type: never listed
        $this->delivery('MembershipRenewalSubmitted', 'member', $other->member_id);    // another member
        $this->delivery('TaskReassignedAway', 'user', $other->id);
        UserAccountEvent::create(['user_id' => $user->id, 'type' => UserAccountEvent::ACTIVATED, 'new_value' => 'member']);

        $notes = app(MemberPortalService::class)->notifications($user, $user->member);
        $this->assertSame(['Task assigned', 'Membership approved'], $notes->pluck('label')->all());

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('Membership approved')->assertSee('Emailed')
            ->assertSee('Could not be emailed')->assertDontSee('535')->assertDontSee('secret-hint')
            ->assertDontSee('Test email')->assertDontSee('Renewal submitted')->assertDontSee('Task reassigned away')
            ->assertDontSee('someone@inbox.memberflow.dev')
            ->assertSee('Account activated');
    }

    // ---- Printable record -----------------------------------------------------------------------

    public function test_member_prints_only_their_own_record(): void
    {
        $user = $this->account(['first_name' => 'Petra', 'last_name' => 'Print']);
        $other = $this->account(['first_name' => 'Oscar', 'last_name' => 'Other']);
        $this->transaction($user, ['amount' => '2000.00']);

        $this->actingAs($user)->get(route('my.membership.print'))->assertOk()
            ->assertSee('Membership record')->assertSee('Petra Print')->assertSee($user->member->membership_no)
            ->assertSee($user->member->level->name)->assertDontSee('Oscar')->assertDontSee($other->member->membership_no);
        $this->get(route('my.membership.print', ['member' => $other->member_id]))->assertOk()->assertDontSee('Oscar');
        $this->get(route('admin.members.print', $other->member))->assertForbidden();
        $this->get(route('admin.members.print', $user->member))->assertForbidden(); // admin route stays admin-only

        $this->post('/logout');
        $this->get(route('my.membership.print'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->admin()->create())->get(route('my.membership.print'))->assertForbidden();
    }

    // ---- Layout ------------------------------------------------------------------------------------

    public function test_portal_pages_use_the_responsive_layout(): void
    {
        $user = $this->account();
        $this->transaction($user, ['amount' => '1000.00']);
        $this->actingAs($user);

        foreach (['dashboard', 'my.membership', 'my.payments.index', 'my.tasks.index', 'my.history'] as $route) {
            $this->get(route($route))->assertOk()
                ->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1">', false)
                ->assertSee('data-sidebar-toggle', false)                  // mobile navigation drawer
                ->assertSee('Skip to content')
                ->assertSee(route('my.payments.index'), false);           // member navigation present
        }
        $this->get(route('dashboard'))->assertSee('grid-cols-2 gap-4 lg:grid-cols-4', false)->assertSee('xl:grid-cols-3', false);
        $this->get(route('my.payments.index'))->assertSee('table-wrap', false);
    }

    // ---- Compatibility --------------------------------------------------------------------------------

    public function test_admin_and_member_pages_work(): void
    {
        $member = $this->account();
        Task::factory()->create(['assigned_to' => $member->id, 'title' => 'Member duty']);

        $this->actingAs($member)->get(route('dashboard'))->assertOk()->assertSee('Member duty')->assertSee('Current weather');
        $this->get(route('my.tasks.index'))->assertOk()->assertSee('Member duty')->assertDontSee('Your tasks are read-only');
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.reports.index'))->assertOk();
    }
}
