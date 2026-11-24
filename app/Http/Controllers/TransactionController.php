<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\MembershipException;
use App\Http\Requests\CancelTransactionRequest;
use App\Http\Requests\StoreTransactionRequest;
use App\Models\Member;
use App\Models\MembershipLevel;
use App\Models\MembershipTransaction;
use App\Services\MembershipService;
use App\Services\PaymentService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Membership transactions (applications and renewals): directory, creation,
 * details, approval, cancellation and expiry. Admin-only (route middleware +
 * MembershipTransactionPolicy); state rules live in MembershipService.
 */
class TransactionController extends Controller
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly PaymentService $payments,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', MembershipTransaction::class);

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => TransactionStatus::tryFrom((string) $request->query('status')),
            'type' => TransactionType::tryFrom((string) $request->query('type')),
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
        ];

        $transactions = MembershipTransaction::query()
            ->with(['member', 'level'])
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $term = $filters['q'];
                $query->where(fn ($q) => $q
                    ->where('reference_no', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%')
                    ->orWhereHas('member', fn ($m) => $m->search($term)));
            })
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s->value))
            ->when($filters['type'], fn ($q, $t) => $q->where('type', $t->value))
            ->when($filters['from'], fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filters['to'], fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest()
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('transactions.index', [
            'transactions' => $transactions,
            'filters' => $filters,
            'hasFilters' => collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty(),
            'statusCounts' => MembershipTransaction::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'dueForExpiry' => MembershipTransaction::where('status', TransactionStatus::Approved->value)
                ->whereDate('period_end', '<', today())->count(),
        ]);
    }

    /**
     * Step 1: pick a member (search). Step 2: confirm application/renewal for that member.
     */
    public function create(Request $request): View
    {
        $this->authorize('create', MembershipTransaction::class);

        $member = $request->filled('member')
            ? Member::with(['category', 'level'])->find((int) $request->query('member'))
            : null;

        if (! $member) {
            $q = trim((string) $request->query('q', ''));
            $candidates = $q === '' ? collect() : Member::with(['level', 'openTransaction'])
                ->search($q)->orderBy('last_name')->limit(10)->get();

            return view('transactions.pick-member', compact('q', 'candidates'));
        }

        $applicationBlocker = $this->memberships->applicationBlocker($member);
        $renewalBlocker = $this->memberships->renewalBlocker($member);
        $requested = TransactionType::tryFrom((string) $request->query('type'));
        $type = $requested ?? ($applicationBlocker === null ? TransactionType::Application : TransactionType::Renewal);

        return view('transactions.create', [
            'member' => $member,
            'type' => $type,
            'applicationBlocker' => $applicationBlocker,
            'renewalBlocker' => $renewalBlocker,
            'levels' => MembershipLevel::active()->ordered()->get(),
            'windowDays' => $this->memberships->renewalWindowDays(),
        ]);
    }

    public function store(StoreTransactionRequest $request): RedirectResponse
    {
        $member = Member::findOrFail($request->validated('member_id'));
        $remarks = $request->validated('remarks');

        try {
            $transaction = match ($request->transactionType()) {
                TransactionType::Application => $this->memberships->openApplication($member, $request->user(), $remarks),
                TransactionType::Renewal => $this->memberships->openRenewal(
                    $member, MembershipLevel::findOrFail($request->validated('membership_level_id')), $request->user(), $remarks,
                ),
            };
        } catch (MembershipException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.transactions.show', $transaction)->with('success',
            "{$transaction->type->label()} {$transaction->reference_no} created for {$member->fullName()} "
            ."({$transaction->status->label()}).");
    }

    public function show(MembershipTransaction $transaction): View
    {
        $this->authorize('view', $transaction);

        $transaction->load(['member.category', 'member.level', 'level', 'approver', 'canceller', 'creator',
            'payments' => fn ($q) => $q->with('receiver')->orderBy('paid_at')]);

        return view('transactions.show', [
            'transaction' => $transaction,
            'approvalBlocker' => $this->memberships->approvalBlocker($transaction),
            'cancellationBlocker' => $this->memberships->cancellationBlocker($transaction),
            'paymentBlocker' => $this->payments->paymentBlocker($transaction),
            'previewPeriod' => $this->previewPeriod($transaction),
        ]);
    }

    public function approve(Request $request, MembershipTransaction $transaction): RedirectResponse
    {
        $this->authorize('approve', $transaction);

        try {
            $approved = $this->memberships->approve($transaction, $request->user());
        } catch (MembershipException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$approved->reference_no} approved. Membership valid "
            .$approved->period_start->format('M j, Y').' – '.$approved->period_end->format('M j, Y').'.');
    }

    public function cancel(CancelTransactionRequest $request, MembershipTransaction $transaction): RedirectResponse
    {
        try {
            $this->memberships->cancel($transaction, $request->user(), $request->validated('cancellation_reason'));
        } catch (MembershipException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$transaction->reference_no} was cancelled. The record is kept for history.");
    }

    /** Manually run the same expiry check the daily scheduler runs. */
    public function expire(): RedirectResponse
    {
        $this->authorize('runExpiry', MembershipTransaction::class);

        $result = $this->memberships->expireDue();

        return back()->with('success', "Expiry check complete: {$result['transactions']} "
            .str('transaction')->plural($result['transactions']).' and '
            ."{$result['members']} ".str('member')->plural($result['members']).' marked expired.');
    }

    /** What approving today would produce (shown before approval). */
    private function previewPeriod(MembershipTransaction $transaction): ?array
    {
        if ($transaction->status !== TransactionStatus::Paid || ! $transaction->member) {
            return null;
        }

        $member = $transaction->member;
        $today = today()->toImmutable();
        $start = $transaction->type === TransactionType::Renewal && $member->membership_end?->gte($today)
            ? $member->membership_end->toImmutable()->addDay()
            : $today;

        return [$start, $this->memberships->periodEnd($start, $transaction->validity_months)];
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
