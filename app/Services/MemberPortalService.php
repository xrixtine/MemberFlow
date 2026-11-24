<?php

namespace App\Services;

use App\Enums\EmailDeliveryStatus;
use App\Enums\MemberSource;
use App\Enums\MemberStatus;
use App\Enums\TaskStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\EmailDelivery;
use App\Models\Member;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Models\Task;
use App\Models\TaskProgressUpdate;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Models\UserInvitation;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only data for the member portal.
 *
 * ISOLATION RULE: every query here is scoped to the signed-in account's OWN
 * member record (users.member_id) or the account itself. No method accepts a
 * member id from the request, so another member's data cannot be reached by
 * changing a URL. Organisation-wide totals are never computed here.
 */
class MemberPortalService
{
    /** Emails a member may see in their notification summary (never admin-only mail such as test emails). */
    public const MEMBER_NOTIFICATIONS = [
        'MemberAccountInvitation', 'VerifyApplicantEmail', 'MembershipApplicationReceived', 'MembershipRenewalSubmitted',
        'MembershipApproved', 'MembershipTransactionCancelled', 'ConfirmAccountEmailChange',
        'TaskAssignedNotification', 'TaskReassignedAway',
    ];

    public function __construct(
        private readonly ReportService $reports,
        private readonly MembershipService $memberships,
    ) {}

    /** The account's own member record. 403 if the account has no valid member link. */
    public function memberFor(User $user): Member
    {
        abort_unless($user->isMember() && $user->hasValidMemberLink(), 403, 'This account is not linked to a membership record.');

        return $user->member->loadMissing(['category', 'level']);
    }

    /** @return Builder<MembershipTransaction> */
    public function transactions(Member $member): Builder
    {
        return MembershipTransaction::query()->where('member_id', $member->id);
    }

    /** @return Builder<Payment> */
    public function payments(Member $member): Builder
    {
        return Payment::query()->whereHas('transaction', fn (Builder $q) => $q->where('member_id', $member->id));
    }

    // ---- Membership -------------------------------------------------------------

    /** Membership validity in plain terms (days left counts down to the last valid day). */
    public function validity(Member $member): array
    {
        $end = $member->membership_end;
        $current = $member->status === MemberStatus::Active && $end !== null && ! $end->lt(today());
        $daysLeft = $current ? (int) today()->diffInDays($end, false) : null;

        return [
            'status' => $member->status,
            'start' => $member->membership_start,
            'end' => $end,
            'current' => $current,
            'days_left' => $daysLeft,
            'ending_soon' => $daysLeft !== null && $daysLeft <= $this->memberships->renewalWindowDays(),
        ];
    }

    /**
     * The member's application/renewal in progress (pending or paid), otherwise the latest
     * transaction, explained in plain language. Null when there is no transaction at all.
     *
     * @return array{transaction: MembershipTransaction, headline: string, detail: string, tone: string}|null
     */
    public function applicationStatus(Member $member): ?array
    {
        $txn = $this->transactions($member)->whereIn('status', [TransactionStatus::Pending->value, TransactionStatus::Paid->value])
            ->latest()->latest('id')->first()
            ?? $this->transactions($member)->latest()->latest('id')->first();
        if (! $txn) {
            return null;
        }

        $type = $txn->type === TransactionType::Renewal ? 'renewal' : 'application';
        [$headline, $detail, $tone] = match ($txn->status) {
            TransactionStatus::Pending => ["Your {$type} is waiting for payment",
                'Balance due: '.$txn->formattedBalance().'. Pay at the membership office; partial payments are accepted.', 'amber'],
            TransactionStatus::Paid => $member->source === MemberSource::Online && ! $member->isEmailVerified()
                ? ["Your {$type} is paid — please verify your email", 'An administrator can approve it once your email address is verified.', 'amber']
                : ["Your {$type} is paid and awaiting approval", 'An administrator will approve it shortly. No further payment is needed.', 'blue'],
            TransactionStatus::Approved => ['Your latest '.$type.' was approved',
                'Approved '.$txn->approved_at?->format('F j, Y').($txn->period_end ? '; valid until '.$txn->period_end->format('F j, Y').'.' : '.'), 'green'],
            TransactionStatus::Cancelled => ["Your latest {$type} was cancelled",
                'Cancelled '.$txn->cancelled_at?->format('F j, Y').'. Contact the membership office if you have questions.', 'gray'],
            TransactionStatus::Expired => ["Your {$type} period has ended", 'Contact the membership office to renew.', 'red'],
        };

        return ['transaction' => $txn, 'headline' => $headline, 'detail' => $detail, 'tone' => $tone];
    }

    /**
     * Renewal eligibility for the member, using the SAME rules administrators use
     * (MembershipService::renewalBlocker). Members never create renewals themselves.
     *
     * @return array{eligible: bool, message: string, opens_on: ?Carbon}
     */
    public function renewal(Member $member): array
    {
        $blocker = $this->memberships->renewalBlocker($member);
        $window = $this->memberships->renewalWindowDays();
        $opensOn = $member->membership_end?->copy()->subDays($window);

        if ($blocker === null) {
            return ['eligible' => true, 'opens_on' => null, 'message' => $member->status === MemberStatus::Expired
                ? 'Your membership has expired. You can renew now: visit or contact the membership office and an administrator will set up your renewal.'
                : 'You can renew now. Visit or contact the membership office and an administrator will set up your renewal.'];
        }

        $message = match (true) {
            $member->status === MemberStatus::Pending || $member->membership_end === null => 'Renewal becomes available after your first application is approved.',
            $this->memberships->hasOpenTransaction($member) => 'You already have an application or renewal in progress.',
            $member->status === MemberStatus::Active => "Renewal opens {$window} days before your membership ends, on ".$opensOn->format('F j, Y').'.',
            default => 'Renewal is not available right now. Please contact the membership office.',
        };

        return ['eligible' => false, 'opens_on' => $member->status === MemberStatus::Active ? $opensOn : null, 'message' => $message];
    }

    // ---- Payments ------------------------------------------------------------------

    /**
     * The member's own payment figures (integer centavos internally; never floats).
     * Charges exclude cancelled transactions; collections exclude voided payments.
     */
    public function paymentSummary(Member $member): array
    {
        $valid = $this->payments($member)->whereNull('voided_at')->get(['id', 'amount', 'paid_at', 'receipt_no']);
        $charged = $this->transactions($member)->where('status', '!=', TransactionStatus::Cancelled->value)->get(['id', 'amount', 'amount_paid', 'status']);
        $open = $this->transactions($member)->where('status', TransactionStatus::Pending->value)->latest()->latest('id')->first();
        $outstanding = $charged->where('status', TransactionStatus::Pending)->sum(fn (MembershipTransaction $t) => $t->balanceCents());

        return [
            'total_charges' => Money::format($charged->sum(fn (MembershipTransaction $t) => $t->amountCents())),
            'total_paid' => Money::format($valid->sum(fn (Payment $p) => $p->amountCents())),
            'outstanding' => Money::format($outstanding),
            'outstanding_cents' => $outstanding,
            'count' => $valid->count(),
            'voided_count' => $this->payments($member)->whereNotNull('voided_at')->count(),
            'last' => $valid->sortByDesc('paid_at')->first(),
            'open_transaction' => $open,
            'balance' => $open ? Money::format($open->balanceCents()) : null,
        ];
    }

    // ---- Tasks -------------------------------------------------------------------------

    /** Task figures for the signed-in account's OWN assignments. */
    public function taskSummary(User $user): array
    {
        $status = $this->reports->taskStatusCounts($user);
        $mine = fn () => Task::active()->assignedTo($user);
        $total = array_sum($status);
        $completed = $status[TaskStatus::Completed->value];

        return [
            'total' => $total,
            'open' => $total - $completed,
            'todo' => $status[TaskStatus::Todo->value],
            'in_progress' => $status[TaskStatus::InProgress->value],
            'on_hold' => $status[TaskStatus::OnHold->value],
            'completed' => $completed,
            'completion_rate' => $total > 0 ? (int) round($completed / $total * 100) : 0,
            'overdue' => $mine()->overdue()->count(),
            'previous' => Task::query()->where(fn ($q) => $q->whereNull('assigned_to')->orWhere('assigned_to', '!=', $user->id))
                ->whereHas('assignments', fn ($a) => $a->where('user_id', $user->id))->count(),
            'current' => $mine()->where('status', '!=', TaskStatus::Completed->value)
                ->orderByRaw('due_date IS NULL, due_date')->limit(5)->get(),
            'upcoming' => $mine()->where('status', '!=', TaskStatus::Completed->value)
                ->whereBetween('due_date', [today(), today()->addDays(7)])->orderBy('due_date')->limit(5)->get(),
        ];
    }

    // ---- Notifications and activity ------------------------------------------------------

    /**
     * Emails MemberFlow sent (or tried to send) to this member: to their account, to their
     * member record, or their activation invitations. Only member-facing email types; no
     * error messages, recipients, tokens or links are exposed.
     *
     * @return Collection<int, array{label: string, at: Carbon, state: string, tone: string}>
     */
    public function notifications(User $user, Member $member, int $limit = 6): Collection
    {
        $invitationIds = UserInvitation::where('member_id', $member->id)->pluck('id');

        return EmailDelivery::query()
            ->whereIn('notification', self::MEMBER_NOTIFICATIONS)
            ->where(fn (Builder $q) => $q
                ->where(fn ($u) => $u->where('notifiable_type', 'user')->where('notifiable_id', $user->id))
                ->orWhere(fn ($m) => $m->where('notifiable_type', 'member')->where('notifiable_id', $member->id))
                ->orWhere(fn ($i) => $i->where('related_type', 'invitation')->whereIn('related_id', $invitationIds)))
            ->latest()->latest('id')->limit($limit)
            ->get(['id', 'notification', 'status', 'created_at'])
            ->map(fn (EmailDelivery $d) => ['label' => $d->typeLabel(), 'at' => $d->created_at] + match ($d->status) {
                EmailDeliveryStatus::Sent => ['state' => 'Emailed', 'tone' => 'green'],
                EmailDeliveryStatus::Queued => ['state' => 'Being sent', 'tone' => 'blue'],
                EmailDeliveryStatus::Failed => ['state' => 'Could not be emailed', 'tone' => 'red'],
                default => ['state' => 'Recorded (not emailed)', 'tone' => 'gray'],
            });
    }

    /**
     * Recent events for this member, newest first: their own transactions, payments,
     * account events and changes on tasks they hold or held. Notes are not included
     * (they may mention other people); only event type, title and date.
     *
     * @return Collection<int, array{at: Carbon, label: string, detail: string, icon: string}>
     */
    public function activity(User $user, Member $member, int $limit = 8): Collection
    {
        $events = collect();

        foreach ($this->transactions($member)->latest()->limit($limit)->get() as $t) {
            $type = $t->type->label();
            $events->push(['at' => $t->created_at, 'label' => "{$type} submitted", 'detail' => (string) $t->reference_no, 'icon' => 'receipt']);
            if ($t->approved_at) {
                $events->push(['at' => $t->approved_at, 'label' => "{$type} approved", 'detail' => (string) $t->reference_no, 'icon' => 'check']);
            }
            if ($t->cancelled_at) {
                $events->push(['at' => $t->cancelled_at, 'label' => "{$type} cancelled", 'detail' => (string) $t->reference_no, 'icon' => 'alert']);
            }
        }

        foreach ($this->payments($member)->latest('paid_at')->limit($limit)->get(['id', 'amount', 'paid_at', 'receipt_no', 'voided_at']) as $p) {
            $events->push(['at' => $p->paid_at, 'label' => 'Payment received', 'detail' => $p->formattedAmount().' · '.$p->receipt_no, 'icon' => 'wallet']);
            if ($p->voided_at) {
                $events->push(['at' => $p->voided_at, 'label' => 'Payment voided', 'detail' => (string) $p->receipt_no, 'icon' => 'alert']);
            }
        }

        foreach (UserAccountEvent::where('user_id', $user->id)->latest()->limit($limit)->get() as $e) {
            $events->push(['at' => $e->created_at, 'label' => $e->label(), 'detail' => '', 'icon' => 'user']);
        }

        $taskIds = Task::query()->whereHas('assignments', fn ($a) => $a->where('user_id', $user->id))->pluck('id');
        $types = [TaskProgressUpdate::ASSIGNED, TaskProgressUpdate::REASSIGNED, TaskProgressUpdate::COMPLETED, TaskProgressUpdate::REOPENED, TaskProgressUpdate::ARCHIVED];
        foreach (TaskProgressUpdate::whereIn('task_id', $taskIds)->whereIn('type', $types)->with('task:id,title')->latest()->latest('id')->limit($limit)->get() as $u) {
            $events->push(['at' => $u->created_at, 'label' => 'Task '.strtolower($u->label()), 'detail' => (string) $u->task?->title, 'icon' => 'tasks']);
        }

        return $events->sortByDesc('at')->take($limit)->values();
    }

    /** Eager-load constraint for the member's transactions with their payments. */
    public static function withPayments(): array
    {
        return ['level', 'payments' => fn (HasMany $q) => $q->orderBy('paid_at')->orderBy('id')];
    }
}
