<?php

namespace Tests\Feature\Reports;

use App\Enums\PaymentMethod;
use App\Models\User;
use App\Services\ReportService;
use Carbon\CarbonImmutable;

class ReportsTest extends ReportTestCase
{
    private function period(): array
    {
        return [CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-12')];
    }

    // ---- Service calculations -------------------------------------------------------

    public function test_member_counts_by_status_category_and_level(): void
    {
        $reports = app(ReportService::class);

        $this->assertSame(['pending' => 1, 'active' => 2, 'expired' => 1, 'inactive' => 1], $reports->memberStatusCounts());

        $byCategory = $reports->membersBy('category')->keyBy('name');
        $this->assertSame(['Corporate', 'Regular', 'Student'], $byCategory->keys()->all()); // empty categories included
        $this->assertSame(3, $byCategory['Regular']['total']);
        $this->assertSame(1, $byCategory['Regular']['counts']['inactive']);
        $this->assertSame(2, $byCategory['Student']['total']);   // soft-deleted member excluded
        $this->assertSame(0, $byCategory['Corporate']['total']);

        $byLevel = $reports->membersBy('level');
        $this->assertSame(['Silver', 'Gold'], $byLevel->pluck('name')->all());  // sort_order
        $this->assertSame(2, $byLevel[1]['counts']['active']);
    }

    public function test_transactions_opened_in_period_exclude_cancelled_from_billing(): void
    {
        [$from, $to] = $this->period();
        $opened = app(ReportService::class)->transactionsOpened($from, $to);

        $this->assertSame(3, $opened['application']['total']);          // T1, T2, T4 (T6 is outside)
        $this->assertSame(1, $opened['application']['counts']['cancelled']);
        $this->assertSame(300000, $opened['application']['billed']);     // 2000 + 1000; cancelled T4 excluded
        $this->assertSame(230000, $opened['application']['paid']);       // 2000 + 300
        $this->assertSame(2, $opened['renewal']['total']);
        $this->assertSame(300000, $opened['renewal']['billed']);
        $this->assertSame(['pending' => 2, 'paid' => 1, 'approved' => 2, 'expired' => 0, 'cancelled' => 1],
            app(ReportService::class)->transactionStatusCounts());
    }

    public function test_payments_exclude_voided_and_respect_the_period(): void
    {
        [$from, $to] = $this->period();
        $payments = app(ReportService::class)->payments($from, $to);

        $this->assertSame(430000, $payments['total']);            // 2000 + 300 + 2000 (voided 500 and September 2000 excluded)
        $this->assertSame(3, $payments['count']);
        $this->assertSame(1, $payments['voided_count']);
        $this->assertSame(50000, $payments['voided_total']);
        $methods = $payments['methods']->keyBy('label');
        $this->assertSame(200000, $methods['Cash']['total']);
        $this->assertSame(30000, $methods['GCash']['total']);
        $this->assertSame(200000, $methods[PaymentMethod::BankTransfer->label()]['total']);
        $this->assertSame(['2026-11-05', '2026-11-06', '2026-11-07'], $payments['daily']->map(fn ($d) => $d['day']->format('Y-m-d'))->all());

        $this->assertSame(630000, app(ReportService::class)->validCollected());   // all time, voided excluded
    }

    public function test_outstanding_balances_cover_open_transactions_only(): void
    {
        $reports = app(ReportService::class);

        $this->assertSame(['total' => 170000, 'count' => 2], $reports->outstandingTotals());  // 700 + 1000; cancelled excluded
        // Largest balance first: Cy owes 1,000, Ben owes 700. Paid/approved/cancelled are not listed.
        $this->assertSame(['CyMember', 'BenMember'], $reports->outstandingList()->map(fn ($t) => $t->member->first_name)->all());
    }

    public function test_task_statistics_and_staff_workload(): void
    {
        [$from, $to] = $this->period();
        $reports = app(ReportService::class);
        $tasks = $reports->tasks($from, $to);

        $this->assertSame(['todo' => 1, 'in_progress' => 1, 'on_hold' => 1, 'completed' => 2], $tasks['status']); // archived excluded
        $this->assertSame(5, $tasks['total']);
        $this->assertSame(3, $tasks['open']);
        $this->assertSame(1, $tasks['overdue']);
        $this->assertSame(1, $tasks['archived']);
        $this->assertSame(2, $tasks['completed_in_period']);
        $this->assertSame(1, $tasks['completed_on_time']);
        $this->assertSame(50, $tasks['on_time_rate']);
        $this->assertSame(40, $tasks['completion_rate']);

        $workload = $reports->staffWorkload($from, $to)->keyBy(fn ($r) => $r['user']->name);
        $this->assertSame(3, $workload['Ana Worker']['open']);
        $this->assertSame(1, $workload['Ana Worker']['overdue']);
        $this->assertSame(1, $workload['Ana Worker']['on_hold']);
        $this->assertSame(27, $workload['Ana Worker']['average_progress']);   // (0 + 60 + 20) / 3
        $this->assertSame(1, $workload['Ana Worker']['completed_in_period']);
        $this->assertSame(0, $workload['Ben Worker']['open']);                 // archived task excluded
        $this->assertNull($workload['Ben Worker']['average_progress']);
        $this->assertSame(1, $workload['Ben Worker']['completed_in_period']);
    }

    // ---- Page -----------------------------------------------------------------------------

    public function test_report_page_shows_the_figures_for_the_selected_period(): void
    {
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['from' => '2026-11-01', 'to' => '2026-11-12']))
            ->assertOk()
            ->assertSee('Nov 1, 2026 – Nov 12, 2026')
            ->assertSee('₱4,300.00')            // collections in period
            ->assertSee('₱500.00')              // voided, shown separately
            ->assertSee('₱1,700.00')            // outstanding
            ->assertSee('BenMember')
            ->assertSee('Ana Worker')
            ->assertSee('data-print', false)
            ->assertSee('Corporate');

        // A period covering September includes the older payment.
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk()->assertSee('₱2,000.00')->assertDontSee('₱4,300.00');
    }

    public function test_default_period_is_this_month(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.reports.index'))->assertOk();
        $this->assertSame('2026-11-01', $response->viewData('from')->format('Y-m-d'));
        $this->assertSame('2026-11-12', $response->viewData('to')->format('Y-m-d'));
    }

    public function test_invalid_periods_are_rejected(): void
    {
        $this->actingAs($this->admin);
        $this->get(route('admin.reports.index', ['from' => '2026-11-10', 'to' => '2026-11-01']))->assertSessionHasErrors('to');
        $this->get(route('admin.reports.index', ['from' => 'yesterday']))->assertSessionHasErrors('from');
        $this->get(route('admin.reports.index', ['from' => '2020-01-01', 'to' => '2026-11-12']))->assertSessionHasErrors('from');
    }

    public function test_reports_are_admin_only(): void
    {
        $this->get(route('admin.reports.index'))->assertRedirect(route('login'));
        $this->actingAs($this->ana)->get(route('admin.reports.index'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->inactive()->create())->get(route('admin.reports.index'))->assertRedirect(route('login'));
    }

    public function test_navigation_links_to_reports_for_admins_only(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee(route('admin.reports.index'));
        $this->actingAs($this->ana)->get(route('dashboard'))->assertDontSee(route('admin.reports.index'));
    }
}
