<?php

namespace App\Http\Controllers;

use App\Enums\MemberSource;
use App\Enums\MemberStatus;
use App\Enums\TransactionStatus;
use App\Exceptions\MembershipException;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\EmailDelivery;
use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\MembershipLevel;
use App\Models\UserInvitation;
use App\Services\ApplicantVerificationService;
use App\Services\EmailDeliveryTracker;
use App\Services\InvitationService;
use App\Services\MemberAccountService;
use App\Services\MembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Member directory and member records (admin-only).
 * Business rules live in MembershipService; this controller stays thin.
 */
class MemberController extends Controller
{
    public function __construct(private readonly MembershipService $memberships) {}

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'category' => (int) $request->query('category') ?: null,
            'level' => (int) $request->query('level') ?: null,
            'status' => MemberStatus::tryFrom((string) $request->query('status')),
            'source' => MemberSource::tryFrom((string) $request->query('source')),
        ];

        $members = Member::query()
            ->with(['category', 'level', 'account:id,member_id,is_active'])
            ->search($filters['q'])
            ->when($filters['category'], fn ($q, $id) => $q->where('membership_category_id', $id))
            ->when($filters['level'], fn ($q, $id) => $q->where('membership_level_id', $id))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status->value))
            ->when($filters['source'], fn ($q, $source) => $q->where('source', $source->value))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(15)
            ->withQueryString();

        return view('members.index', [
            'members' => $members,
            'filters' => $filters,
            'hasFilters' => collect($filters)->filter()->isNotEmpty(),
            'categories' => MembershipCategory::orderBy('name')->pluck('name', 'id'),
            'levels' => MembershipLevel::ordered()->pluck('name', 'id'),
            'statusCounts' => Member::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function create(): View
    {
        return view('members.create', [
            'member' => new Member,
            ...$this->formOptions(),
        ]);
    }

    public function store(StoreMemberRequest $request): RedirectResponse
    {
        $member = $this->memberships->register($request->validated(), $request->user(), $request->wantsApplication());
        $member->load('openTransaction');

        $message = "Member {$member->fullName()} registered with membership no. {$member->membership_no}.";
        $message .= $member->openTransaction
            ? " Application {$member->openTransaction->reference_no} is pending payment — the membership is not active yet."
            : ' No application transaction was created yet.';

        return redirect()->route('admin.members.show', $member)->with('success', $message);
    }

    public function show(Member $member): View
    {
        $member->load([
            'category', 'level', 'creator',
            'transactions' => fn ($q) => $q->with('level')->latest()->latest('id'),
            'account' => fn ($q) => $q->with(['acceptedInvitation', 'accountEvents' => fn ($e) => $e->with('actor')->limit(5)]),
        ]);

        return view('members.show', [
            'member' => $member,
            'accountInvitation' => UserInvitation::where('member_id', $member->id)->with('inviter')->latest('id')->first(),
            'inviteBlocker' => app(InvitationService::class)->memberInvitationBlocker($member),
            'emailChangePending' => app(MemberAccountService::class)->emailChangePending($member),
            'applicationBlocker' => $this->memberships->applicationBlocker($member),
            'renewalBlocker' => $this->memberships->renewalBlocker($member),
            'deliveries' => EmailDelivery::query()
                ->where(fn ($q) => $q->where(fn ($r) => $r->where('related_type', 'member')->where('related_id', $member->id))
                    ->orWhere(fn ($n) => $n->where('notifiable_type', 'member')->where('notifiable_id', $member->id)))
                ->latest()->latest('id')->limit(5)->get(),
        ]);
    }

    public function edit(Member $member): View
    {
        $member->load(['category', 'level']);

        return view('members.edit', [
            'member' => $member,
            'levelLocked' => ! $this->levelEditable($member),
            ...$this->formOptions($member),
        ]);
    }

    public function update(UpdateMemberRequest $request, Member $member): RedirectResponse
    {
        try {
            $this->memberships->update($member, $request->validated(), $request->user());
        } catch (MembershipException $e) {
            return back()->withInput()->withErrors(['membership_level_id' => $e->getMessage()]);
        }

        return redirect()->route('admin.members.show', $member)->with('success', 'Member details updated.');
    }

    /** Admin: (re)send the verification email. Respects the resend cooldown. */
    public function sendVerification(Member $member, ApplicantVerificationService $verification, EmailDeliveryTracker $tracker): RedirectResponse
    {
        if ($member->isEmailVerified()) {
            return back()->with('error', 'This email address is already verified.');
        }
        if ($verification->inCooldown($member)) {
            return back()->with('error', 'A verification email was sent less than a minute ago. Please wait before sending another.');
        }

        $delivery = $verification->sendVerification($member);
        if (! $delivery) {
            return back()->with('error', 'No verification email was sent. Please try again.');
        }

        return back()->with($tracker->isLogMode() || $delivery->status->value === 'skipped' ? 'warning' : 'success',
            $tracker->describe($delivery, 'The verification email'));
    }

    public function deactivate(Member $member): RedirectResponse
    {
        return $this->attempt(fn () => $this->memberships->deactivate($member),
            "{$member->fullName()} has been deactivated. Their records are kept.");
    }

    public function reactivate(Member $member): RedirectResponse
    {
        return $this->attempt(fn () => $this->memberships->reactivate($member),
            "{$member->fullName()} has been reactivated.");
    }

    public function destroy(Member $member): RedirectResponse
    {
        try {
            $this->memberships->delete($member);
        } catch (MembershipException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.members.index')
            ->with('success', "{$member->fullName()} ({$member->membership_no}) was deleted.");
    }

    /**
     * Member transaction history: every application/renewal with its payments
     * and totals. Cancelled transactions are listed but excluded from totals.
     */
    public function history(Member $member): View
    {
        $member->load([
            'category', 'level',
            'transactions' => fn ($q) => $q->with(['level', 'approver', 'payments' => fn ($p) => $p->orderBy('paid_at')])
                ->latest()->latest('id'),
        ]);

        $billable = $member->transactions->reject(fn ($t) => $t->status === TransactionStatus::Cancelled);

        return view('members.history', [
            'member' => $member,
            'totals' => [
                'billed' => $billable->sum(fn ($t) => $t->amountCents()),
                'paid' => $billable->sum(fn ($t) => $t->paidCents()),
                'balance' => $billable->sum(fn ($t) => $t->balanceCents()),
                'approved' => $member->transactions->whereIn('status', [TransactionStatus::Approved, TransactionStatus::Expired])->count(),
            ],
        ]);
    }

    /** Printable membership record. */
    public function print(Member $member): View
    {
        $member->load([
            'category', 'level',
            'transactions' => fn ($q) => $q->with('level')->latest()->latest('id'),
        ]);

        return view('members.print', compact('member'));
    }

    private function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (MembershipException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    private function levelEditable(Member $member): bool
    {
        return $member->status === MemberStatus::Pending
            && ! $member->transactions()->where('amount_paid', '>', 0)->exists();
    }

    /**
     * Active categories/levels for the form, plus the member's current ones
     * even if they were deactivated later.
     *
     * @return array{categories: Collection, levels: Collection}
     */
    private function formOptions(?Member $member = null): array
    {
        $categories = MembershipCategory::query()
            ->where(fn ($q) => $q->where('is_active', true)->when($member?->membership_category_id, fn ($q, $id) => $q->orWhere('id', $id)))
            ->orderBy('name')
            ->get();

        $levels = MembershipLevel::query()
            ->where(fn ($q) => $q->where('is_active', true)->when($member?->membership_level_id, fn ($q, $id) => $q->orWhere('id', $id)))
            ->ordered()
            ->get();

        return compact('categories', 'levels');
    }
}
