<?php

namespace App\Http\Controllers;

use App\Enums\EmailDeliveryStatus;
use App\Enums\MemberSource;
use App\Enums\MemberStatus;
use App\Enums\TaskStatus;
use App\Models\EmailDelivery;
use App\Models\Member;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Models\TaskProgressUpdate;
use App\Services\MemberAccountService;
use App\Services\MemberPortalService;
use App\Services\ReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Role dashboards. Every figure is a live database aggregate (see ReportService for the rules).
 */
class DashboardController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    /** "/" — send each user to their own dashboard. */
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route($request->user()->homeRoute());
    }

    public function admin(): View
    {
        $stats = $this->reports->adminDashboard();

        $status = $this->reports->taskStatusCounts();
        $taskStats = [
            'total' => array_sum($status),
            'todo' => $status[TaskStatus::Todo->value],
            'in_progress' => $status[TaskStatus::InProgress->value],
            'on_hold' => $status[TaskStatus::OnHold->value],
            'completed' => $status[TaskStatus::Completed->value],
            'overdue' => $stats['overdue_tasks'],
        ];

        $recentTransactions = MembershipTransaction::with(['member' => fn ($q) => $q->withTrashed()])
            ->latest()->latest('id')->limit(5)->get();

        $recentPayments = Payment::with(['transaction.member' => fn ($q) => $q->withTrashed()])
            ->latest('paid_at')->latest('id')->limit(5)->get();

        $recentMembers = Member::with('level')->latest()->latest('id')->limit(5)->get();

        $recentActivity = TaskProgressUpdate::query()
            ->whereHas('task')
            ->with(['task', 'user'])
            ->latest()
            ->latest('id')
            ->limit(6)
            ->get();

        $email = [
            'failed' => EmailDelivery::failedUnresolved()->count(),
            'stale' => EmailDelivery::stale()->count(),
            'queued' => EmailDelivery::where('status', EmailDeliveryStatus::Queued->value)->count(),
            'jobs' => DB::table('jobs')->count(),
            'unverified_online' => Member::where('source', MemberSource::Online->value)
                ->where('status', MemberStatus::Pending->value)
                ->whereNull('email_verified_at')
                ->count(),
        ];

        return view('dashboard.admin', compact(
            'stats', 'taskStats', 'recentTransactions', 'recentPayments', 'recentMembers', 'recentActivity', 'email'
        ));
    }

    /**
     * "/dashboard" (role:member) — the member dashboard: the signed-in member's OWN
     * membership, payments and tasks. No organisation-wide figures and nothing about
     * other members. (The staff dashboard was retired with the staff role in R6.)
     */
    public function workspace(Request $request, MemberPortalService $portal): View
    {
        $user = $request->user();
        $member = $portal->memberFor($user);

        return view('dashboard.member', [
            'member' => $member,
            'validity' => $portal->validity($member),
            'payments' => $portal->paymentSummary($member),
            'tasks' => $portal->taskSummary($user),
            'application' => $portal->applicationStatus($member),
            'renewal' => $portal->renewal($member),
            'notifications' => $portal->notifications($user, $member),
            'activity' => $portal->activity($user, $member),
            'emailChangePending' => app(MemberAccountService::class)->emailChangePending($member, $user),
        ]);
    }
}
