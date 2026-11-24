<?php

namespace Tests\Feature\Reports;

use App\Enums\EmailDeliveryStatus;
use App\Models\EmailDelivery;
use App\Models\Task;
use App\Models\TaskProgressUpdate;
use App\Models\User;

class DashboardStatisticsTest extends ReportTestCase
{
    public function test_admin_dashboard_figures_follow_the_business_rules(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $s = $response->viewData('stats');

        $this->assertSame(5, $s['total_members']);           // soft-deleted member excluded
        $this->assertSame(2, $s['active_members']);
        $this->assertSame(1, $s['pending_members']);
        $this->assertSame(1, $s['expired_members']);
        $this->assertSame(1, $s['inactive_members']);
        $this->assertSame(1, $s['pending_applications']);    // T2 (T4 is cancelled)
        $this->assertSame(2, $s['pending_renewals']);        // T3 pending + T5 paid
        $this->assertSame(3, $s['pending_transactions']);    // open = pending + paid
        $this->assertSame(2, $s['awaiting_payment']);
        $this->assertSame(1, $s['awaiting_approval']);
        $this->assertSame(2, $s['approved_transactions']);
        $this->assertSame(630000, $s['collected_total']);    // voided 500 excluded
        $this->assertSame(430000, $s['collected_month']);    // November only
        $this->assertSame(170000, $s['outstanding_total']);  // cancelled excluded
        $this->assertSame(2, $s['outstanding_count']);
        $this->assertSame(3, $s['open_tasks']);
        $this->assertSame(1, $s['overdue_tasks']);

        $tasks = $response->viewData('taskStats');
        $this->assertSame(['total' => 5, 'todo' => 1, 'in_progress' => 1, 'on_hold' => 1, 'completed' => 2, 'overdue' => 1], $tasks);

        $response->assertSee('₱6,300.00')->assertSee('₱1,700.00')->assertSee('Voided')
            ->assertSee('Recent membership activity')->assertSee('Recent payments')->assertSee('Email delivery');
    }

    public function test_admin_dashboard_shows_email_queue_and_failures(): void
    {
        EmailDelivery::create(['notification' => 'TestEmail', 'recipient_email' => 'x@inbox.memberflow.dev', 'mailer' => 'smtp',
            'status' => EmailDeliveryStatus::Failed, 'queued_at' => now(), 'failed_at' => now()]);
        EmailDelivery::create(['notification' => 'TestEmail', 'recipient_email' => 'y@inbox.memberflow.dev', 'mailer' => 'smtp',
            'status' => EmailDeliveryStatus::Queued, 'queued_at' => now()]);

        $email = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->viewData('email');
        $this->assertSame(1, $email['failed']);
        $this->assertSame(1, $email['queued']);
    }

    public function test_dashboard_runs_a_bounded_number_of_queries(): void
    {
        \DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $admin = count(\DB::getQueryLog());

        // Adding more rows must not add queries (no N+1).
        foreach (range(1, 5) as $i) {
            Task::factory()->create(['assigned_to' => $this->ana->id, 'created_by' => $this->admin->id]);
        }
        \DB::flushQueryLog();
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $this->assertSame($admin, count(\DB::getQueryLog()));
    }

    public function test_member_dashboard_shows_only_own_work_and_no_organisation_figures(): void
    {
        $other = Task::factory()->create(['assigned_to' => $this->ben->id, 'title' => 'Someone else task']);
        TaskProgressUpdate::create(['task_id' => $other->id, 'user_id' => $this->ben->id, 'type' => 'note', 'note' => 'Ben private note']);
        $mine = Task::where('title', 'Ana working')->sole();
        TaskProgressUpdate::create(['task_id' => $mine->id, 'user_id' => $this->ana->id, 'type' => 'note', 'note' => 'Ana note']);
        EmailDelivery::create(['notification' => 'TaskAssignedNotification', 'recipient_email' => $this->ana->email, 'mailer' => 'log',
            'notifiable_type' => 'user', 'notifiable_id' => $this->ana->id, 'related_type' => 'task', 'related_id' => $mine->id,
            'status' => EmailDeliveryStatus::Logged, 'queued_at' => now()]);
        EmailDelivery::create(['notification' => 'TaskAssignedNotification', 'recipient_email' => $this->ben->email, 'mailer' => 'log',
            'notifiable_type' => 'user', 'notifiable_id' => $this->ben->id, 'related_type' => 'task', 'related_id' => $other->id,
            'status' => EmailDeliveryStatus::Logged, 'queued_at' => now()]);

        $response = $this->actingAs($this->ana)->get(route('dashboard'))->assertOk();

        $s = $response->viewData('tasks');
        $this->assertSame(3, $s['open']);
        $this->assertSame(1, $s['todo']);
        $this->assertSame(1, $s['in_progress']);
        $this->assertSame(1, $s['on_hold']);
        $this->assertSame(1, $s['completed']);
        $this->assertSame(1, $s['overdue']);

        $this->assertCount(1, $response->viewData('notifications'));   // only Ana's own email

        // Ana sees her own membership money (T1: ₱2,000.00 paid), never organisation totals or other members.
        $response->assertSee('My current tasks')->assertSee('On Hold')->assertSee('Ana working')
            ->assertSee('Notifications')->assertSee('Task assigned')->assertSee('₱2,000.00')
            ->assertDontSee('Someone else task')->assertDontSee('Ben private note')
            ->assertDontSee('₱6,300.00')->assertDontSee('₱1,700.00')->assertDontSee('BenMember')->assertDontSee('EveMember')
            ->assertDontSee(route('admin.reports.index'));
    }

    public function test_staff_cannot_open_admin_pages(): void
    {
        foreach (['admin.dashboard', 'admin.reports.index', 'admin.members.index', 'admin.payments.index', 'admin.email-deliveries.index'] as $route) {
            $this->actingAs($this->ana)->get(route($route))->assertForbidden();
        }
    }

    public function test_both_dashboards_render_empty_states_on_an_empty_database(): void
    {
        $fresh = User::factory()->admin()->create();
        // Remove the fixture data that dashboards show (tasks/payments/transactions keep FKs, so use a new member instead).
        $newMember = User::factory()->member()->create();

        $this->actingAs($newMember)->get(route('dashboard'))->assertOk()
            ->assertSee('No open tasks')->assertSee('Nothing yet')->assertSee('No notifications yet')->assertSee('No deadlines this week');
        $this->actingAs($fresh)->get(route('admin.dashboard'))->assertOk();
    }
}
