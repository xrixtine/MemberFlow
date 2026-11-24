<?php

namespace App\Services;

use App\Enums\MemberSource;
use App\Enums\MemberStatus;
use App\Enums\PaymentMethod;
use App\Enums\TaskStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\MembershipLevel;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Models\Task;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Database-driven figures for the dashboards and the Reports page.
 *
 * Business rules applied everywhere:
 *  - Collections count VALID payments only (voided payments are excluded and reported separately).
 *  - Outstanding balances come from OPEN transactions only (pending or paid-but-unapproved);
 *    cancelled, approved and expired transactions owe nothing.
 *  - Member figures count each member once (by current status), so renewals never
 *    double-count people. Transaction figures count transactions.
 *  - Soft-deleted members are excluded from member figures; their transactions and
 *    payments remain in the financial history.
 *  - Task figures exclude archived and trashed tasks unless stated otherwise.
 *  - Money is summed by MySQL as DECIMAL and converted to integer centavos (no floats).
 */
class ReportService
{
    private const OPEN = [TransactionStatus::Pending, TransactionStatus::Paid];

    // =====================================================================
    // Dashboard
    // =====================================================================

    public function adminDashboard(): array
    {
        $members = $this->memberStatusCounts();
        $txn = $this->transactionStatusCounts();
        $outstanding = $this->outstandingTotals();

        return [
            'total_members' => array_sum($members),
            'active_members' => $members[MemberStatus::Active->value] ?? 0,
            'pending_members' => $members[MemberStatus::Pending->value] ?? 0,
            'expired_members' => $members[MemberStatus::Expired->value] ?? 0,
            'inactive_members' => $members[MemberStatus::Inactive->value] ?? 0,
            'pending_applications' => MembershipTransaction::where('type', TransactionType::Application->value)
                ->whereIn('status', $this->values(self::OPEN))->count(),
            'pending_renewals' => MembershipTransaction::where('type', TransactionType::Renewal->value)
                ->whereIn('status', $this->values(self::OPEN))->count(),
            'pending_transactions' => ($txn['pending'] ?? 0) + ($txn['paid'] ?? 0),  // open = awaiting payment or approval
            'awaiting_payment' => $txn['pending'] ?? 0,
            'awaiting_approval' => $txn['paid'] ?? 0,
            'approved_transactions' => $txn['approved'] ?? 0,
            'collected_total' => $this->validCollected(),
            'collected_month' => $this->validCollected(now()->startOfMonth(), now()),
            'outstanding_total' => $outstanding['total'],
            'outstanding_count' => $outstanding['count'],
            'expiring_soon' => Member::where('status', MemberStatus::Active->value)
                ->whereBetween('membership_end', [today(), today()->addDays(30)])->count(),
            'open_tasks' => Task::active()->where('status', '!=', TaskStatus::Completed->value)->count(),
            'overdue_tasks' => Task::active()->overdue()->count(),
        ];
    }

    // =====================================================================
    // Membership
    // =====================================================================

    /** @return array<string, int> status => members */
    public function memberStatusCounts(): array
    {
        $counts = Member::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return collect(MemberStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($counts[$s->value] ?? 0)])->all();
    }

    /**
     * Members by category (or level) and status, including categories/levels with no members.
     *
     * @return Collection<int, array{name: string, active: bool, counts: array<string,int>, total: int}>
     */
    public function membersBy(string $dimension): Collection
    {
        [$model, $column] = $dimension === 'level'
            ? [MembershipLevel::class, 'membership_level_id']
            : [MembershipCategory::class, 'membership_category_id'];

        $rows = Member::query()
            ->selectRaw("{$column} AS group_id, status, COUNT(*) AS total")
            ->groupBy($column, 'status')
            ->toBase()
            ->get()
            ->groupBy('group_id');

        $groups = $dimension === 'level' ? $model::query()->ordered()->get() : $model::query()->orderBy('name')->get();

        return $groups->map(function ($group) use ($rows) {
            $counts = collect(MemberStatus::cases())->mapWithKeys(fn ($s) => [$s->value => 0])->all();
            foreach ($rows->get($group->id, collect()) as $row) {
                $counts[$row->status] = (int) $row->total;
            }

            return ['name' => $group->name, 'active' => (bool) $group->is_active, 'counts' => $counts, 'total' => array_sum($counts)];
        })->values();
    }

    /** @return array{online: int, walk_in: int} */
    public function memberSources(): array
    {
        $counts = Member::query()->selectRaw('source, COUNT(*) AS total')->groupBy('source')->pluck('total', 'source');

        return [
            'online' => (int) ($counts[MemberSource::Online->value] ?? 0),
            'walk_in' => (int) ($counts[MemberSource::WalkIn->value] ?? 0),
        ];
    }

    // =====================================================================
    // Transactions
    // =====================================================================

    /** @return array<string, int> status => transactions (all time) */
    public function transactionStatusCounts(): array
    {
        $counts = MembershipTransaction::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return collect(TransactionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($counts[$s->value] ?? 0)])->all();
    }

    /**
     * Applications and renewals OPENED in the period, by current status.
     * Amounts: billed excludes cancelled transactions; paid is the cached amount_paid
     * (always equal to the sum of non-voided payments).
     */
    public function transactionsOpened(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = MembershipTransaction::query()
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('type, status, COUNT(*) AS total, SUM(amount) AS billed, SUM(amount_paid) AS paid')
            ->groupBy('type', 'status')
            ->toBase()
            ->get();

        $result = [];
        foreach (TransactionType::cases() as $type) {
            $typeRows = $rows->where('type', $type->value);
            $counts = collect(TransactionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($typeRows->firstWhere('status', $s->value)->total ?? 0)])->all();
            $billable = $typeRows->where('status', '!=', TransactionStatus::Cancelled->value);

            $result[$type->value] = [
                'label' => $type->label(),
                'counts' => $counts,
                'total' => array_sum($counts),
                'billed' => $billable->sum(fn ($r) => Money::fromDecimal((string) $r->billed)),
                'paid' => $billable->sum(fn ($r) => Money::fromDecimal((string) $r->paid)),
            ];
        }

        return $result;
    }

    /** @return array{total: int, count: int} centavos and number of open transactions with a balance */
    public function outstandingTotals(): array
    {
        $row = MembershipTransaction::query()
            ->whereIn('status', $this->values(self::OPEN))
            ->whereColumn('amount', '>', 'amount_paid')
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(amount - amount_paid), 0) AS balance')
            ->toBase()
            ->first();

        return ['total' => Money::fromDecimal((string) $row->balance), 'count' => (int) $row->cnt];
    }

    /** Open transactions that still owe money, largest balance first. */
    public function outstandingList(int $limit = 25): Collection
    {
        return MembershipTransaction::query()
            ->with(['member' => fn ($q) => $q->withTrashed(), 'level'])
            ->whereIn('status', $this->values(self::OPEN))
            ->whereColumn('amount', '>', 'amount_paid')
            ->orderByRaw('(amount - amount_paid) DESC')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
    }

    // =====================================================================
    // Payments
    // =====================================================================

    /** Valid (non-voided) collections in centavos, optionally limited to a paid_at range. */
    public function validCollected(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int
    {
        return Money::fromDecimal((string) Payment::query()
            ->whereNull('voided_at')
            ->when($from, fn ($q) => $q->where('paid_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('paid_at', '<=', $to))
            ->sum('amount'));
    }

    /** Collections in the period by method and by day, plus voided payments separately. */
    public function payments(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $range = [$from->startOfDay(), $to->endOfDay()];

        $byMethod = Payment::query()
            ->whereNull('voided_at')->whereBetween('paid_at', $range)
            ->selectRaw('method, COUNT(*) AS cnt, SUM(amount) AS total')
            ->groupBy('method')->toBase()->get()->keyBy('method');

        $methods = collect(PaymentMethod::cases())->map(fn ($m) => [
            'label' => $m->label(),
            'count' => (int) ($byMethod[$m->value]->cnt ?? 0),
            'total' => Money::fromDecimal((string) ($byMethod[$m->value]->total ?? '0')),
        ])->values();

        $daily = Payment::query()
            ->whereNull('voided_at')->whereBetween('paid_at', $range)
            ->selectRaw('DATE(paid_at) AS day, COUNT(*) AS cnt, SUM(amount) AS total')
            ->groupBy('day')->orderBy('day')->toBase()->get()
            ->map(fn ($r) => ['day' => CarbonImmutable::parse($r->day), 'count' => (int) $r->cnt, 'total' => Money::fromDecimal((string) $r->total)]);

        $voided = Payment::query()
            ->whereNotNull('voided_at')->whereBetween('paid_at', $range)
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total')->toBase()->first();

        return [
            'methods' => $methods,
            'daily' => $daily,
            'total' => $methods->sum('total'),
            'count' => $methods->sum('count'),
            'voided_count' => (int) $voided->cnt,
            'voided_total' => Money::fromDecimal((string) $voided->total),
        ];
    }

    // =====================================================================
    // Tasks
    // =====================================================================

    /** @return array<string, int> status => active (non-archived) tasks */
    public function taskStatusCounts(?User $assignee = null): array
    {
        $counts = Task::active()
            ->when($assignee, fn ($q) => $q->assignedTo($assignee))
            ->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return collect(TaskStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($counts[$s->value] ?? 0)])->all();
    }

    public function tasks(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $range = [$from->startOfDay(), $to->endOfDay()];
        $status = $this->taskStatusCounts();
        $total = array_sum($status);

        $completedInPeriod = Task::query()->whereNotNull('completed_at')->whereBetween('completed_at', $range);
        $completed = (clone $completedInPeriod)->count();
        $onTime = (clone $completedInPeriod)
            ->where(fn ($q) => $q->whereNull('due_date')->orWhereRaw('DATE(completed_at) <= due_date'))
            ->count();

        return [
            'status' => $status,
            'total' => $total,
            'open' => $total - $status[TaskStatus::Completed->value],
            'overdue' => Task::active()->overdue()->count(),
            'archived' => Task::archived()->count(),
            'created_in_period' => Task::query()->whereBetween('created_at', $range)->count(),
            'completed_in_period' => $completed,
            'completed_on_time' => $onTime,
            'on_time_rate' => $completed > 0 ? (int) round($onTime / $completed * 100) : null,
            'completion_rate' => $total > 0 ? (int) round($status[TaskStatus::Completed->value] / $total * 100) : null,
        ];
    }

    /**
     * Workload per assignee — members, and former staff through their historical tasks (active tasks currently assigned to them), plus tasks
     * they completed in the period. Includes inactive staff who still hold open tasks.
     */
    public function staffWorkload(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $open = Task::active()
            ->whereNotNull('assigned_to')
            ->where('status', '!=', TaskStatus::Completed->value)
            ->selectRaw('assigned_to, status, COUNT(*) AS total, SUM(progress) AS progress_sum')
            ->groupBy('assigned_to', 'status')
            ->toBase()
            ->get()
            ->groupBy('assigned_to');

        $overdue = Task::active()->overdue()->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, COUNT(*) AS total')->groupBy('assigned_to')->pluck('total', 'assigned_to');

        $completed = Task::query()->whereNotNull('assigned_to')->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('assigned_to, COUNT(*) AS total')->groupBy('assigned_to')->pluck('total', 'assigned_to');

        // Everyone who holds open tasks or completed one in the period (members; retired staff
        // accounts appear only through their historical tasks). Idle members are left out.
        return User::query()
            ->with('member:id,membership_no')
            ->where(fn ($q) => $q
                ->whereIn('id', $open->keys())
                ->orWhereIn('id', $completed->keys()))
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($open, $overdue, $completed) {
                $rows = $open->get($user->id, collect());
                $count = fn (TaskStatus $s) => (int) ($rows->firstWhere('status', $s->value)->total ?? 0);
                $openTotal = (int) $rows->sum('total');

                return [
                    'user' => $user,
                    'todo' => $count(TaskStatus::Todo),
                    'in_progress' => $count(TaskStatus::InProgress),
                    'on_hold' => $count(TaskStatus::OnHold),
                    'open' => $openTotal,
                    'overdue' => (int) ($overdue[$user->id] ?? 0),
                    'completed_in_period' => (int) ($completed[$user->id] ?? 0),
                    'average_progress' => $openTotal > 0 ? (int) round($rows->sum('progress_sum') / $openTotal) : null,
                ];
            });
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @param  list<\BackedEnum>  $cases */
    private function values(array $cases): array
    {
        return array_map(fn ($c) => $c->value, $cases);
    }
}
