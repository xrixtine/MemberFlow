<?php

namespace Tests\Feature;

use App\Enums\TransactionStatus;
use App\Models\Member;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_shows_live_figures(): void
    {
        Member::factory()->count(2)->active()->create();
        Member::factory()->create();
        $transaction = MembershipTransaction::factory()->create(['amount' => '2000.00', 'amount_paid' => '1500.00']);
        MembershipTransaction::factory()->create(['status' => TransactionStatus::Approved]);
        Payment::factory()->for($transaction, 'transaction')->create(['amount' => '1500.00']);
        Payment::factory()->for($transaction, 'transaction')->create(['amount' => '999.00', 'voided_at' => now()]);

        $response = $this->actingAs(User::factory()->admin()->create())->get('/admin/dashboard');

        $response->assertOk();
        $stats = $response->viewData('stats');
        $this->assertSame(5, $stats['total_members']); // 3 here + 2 created by the transaction factories
        $this->assertSame(2, $stats['active_members']);
        $this->assertSame(1, $stats['pending_transactions']);
        $this->assertSame(150000, $stats['collected_total']); // voided payment excluded
        $response->assertSee('₱1,500.00');
        $response->assertSee($transaction->member->fullName());
    }

    public function test_admin_dashboard_empty_state(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('No transactions yet');
    }

    public function test_member_dashboard_counts_only_own_tasks(): void
    {
        $staff = User::factory()->member()->create();
        Task::factory()->create(['assigned_to' => $staff->id, 'title' => 'My open task']);
        Task::factory()->create(['assigned_to' => $staff->id, 'due_date' => today()->subDay(), 'title' => 'My late task']);
        Task::factory()->create(['assigned_to' => $staff->id, 'status' => 'completed', 'progress' => 100]);
        Task::factory()->create(['title' => 'Someone else task']);

        $response = $this->actingAs($staff)->get('/dashboard');

        $response->assertOk()
            ->assertSee('My open task')
            ->assertSee('My late task')
            ->assertDontSee('Someone else task');

        $stats = $response->viewData('tasks');
        $this->assertSame(2, $stats['open']);
        $this->assertSame(1, $stats['overdue']);
        $this->assertSame(1, $stats['completed']);
        $this->assertSame(33, $stats['completion_rate']);
    }
}
