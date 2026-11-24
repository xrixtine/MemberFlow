<?php

namespace App\Services;

use App\Enums\MemberSource;
use App\Enums\MemberStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Events\TransactionApproved;
use App\Events\TransactionCancelled;
use App\Events\TransactionOpened;
use App\Exceptions\MembershipException;
use App\Models\Member;
use App\Models\MembershipLevel;
use App\Models\MembershipTransaction;
use App\Models\User;
use App\Support\Money;
use App\Support\NumberGenerator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Membership business rules: registration, applications, renewals,
 * approval, cancellation and expiry.
 *
 * Status transitions are defined once in TransactionStatus::canTransitionTo().
 * Every state change happens inside a DB transaction with the affected rows
 * locked (SELECT ... FOR UPDATE), so two simultaneous requests cannot approve
 * the same transaction twice or open two conflicting transactions.
 *
 * Registering a member NEVER activates the membership: a member becomes
 * "active" only when a fully paid transaction is approved.
 *
 * Validity convention: a period of N months starting on date S ends on
 * S + N months − 1 day (inclusive). A 12-month membership approved on
 * 2026-11-12 is valid through 2027-11-11. A renewal approved while the
 * membership is still valid starts the day after the current end date,
 * so no days are lost or double-counted.
 */
class MembershipService
{
    /** Attributes an administrator may set directly on a member. */
    public const MEMBER_FIELDS = [
        'first_name', 'middle_name', 'last_name', 'email', 'phone', 'birthdate', 'address',
        'latitude', 'longitude', 'membership_category_id', 'membership_level_id',
    ];

    // =====================================================================
    // Registration and member records
    // =====================================================================

    /**
     * Register a member and (by default) open a pending application transaction,
     * atomically. Numbers are derived from the new primary keys, so they are unique.
     */
    public function register(
        array $data,
        ?User $registeredBy,
        bool $createApplication = true,
        MemberSource $source = MemberSource::WalkIn,
    ): Member {
        return DB::transaction(function () use ($data, $registeredBy, $createApplication, $source) {
            $member = new Member(Arr::only($data, self::MEMBER_FIELDS));
            $member->forceFill([
                'source' => $source,
                'status' => MemberStatus::Pending,
                'created_by' => $registeredBy?->id,
            ]);
            $this->applyLocationConsent($member, $data);
            $member->save();

            $member->forceFill([
                'membership_no' => NumberGenerator::membership($member->id, $member->created_at),
            ])->save();

            if ($createApplication) {
                $this->openApplication($member, $registeredBy);
            }

            return $member;
        });
    }

    /**
     * Update a member's details. The level can only change while the
     * membership is pending and unpaid; the open application is then re-priced.
     */
    public function update(Member $member, array $data, ?User $by = null): Member
    {
        return DB::transaction(function () use ($member, $data, $by) {
            $member = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
            $consent = $data['location_consent'] ?? null;
            $data = Arr::only($data, self::MEMBER_FIELDS);

            $levelChanged = isset($data['membership_level_id'])
                && (int) $data['membership_level_id'] !== (int) $member->membership_level_id;

            if ($levelChanged) {
                $this->assertLevelCanChange($member);
            }

            $emailChanged = isset($data['email'])
                && strcasecmp($data['email'], $member->email) !== 0;
            $oldEmail = (string) $member->email;

            $member->fill($data);

            if (array_key_exists('latitude', $data) || array_key_exists('longitude', $data)) {
                $this->applyLocationConsent($member, $data + ['location_consent' => $consent]);
            }

            // A new address has not been verified yet; old verification links stop working.
            if ($emailChanged) {
                $member->forceFill(['email_verified_at' => null, 'verification_sent_at' => null]);
            }

            $member->save();

            if ($levelChanged) {
                $this->repriceOpenApplication($member);
            }

            // Revoke activation invitations sent to the old address and, for an existing
            // login account, start the confirmed sign-in email change (never silent).
            if ($emailChanged) {
                app(MemberAccountService::class)->memberEmailChanged($member, $oldEmail, $by);
            }

            return $member;
        });
    }

    public function deactivate(Member $member): Member
    {
        if ($member->status === MemberStatus::Inactive) {
            throw new MembershipException('This member is already inactive.');
        }

        $member->forceFill(['status' => MemberStatus::Inactive])->save();

        return $member;
    }

    /** Restore an inactive member to the status their membership dates imply. */
    public function reactivate(Member $member): Member
    {
        if ($member->status !== MemberStatus::Inactive) {
            throw new MembershipException('Only inactive members can be reactivated.');
        }

        $member->forceFill(['status' => $this->statusFromDates($member)])->save();

        return $member;
    }

    /** Soft-delete a member who has no transactions; otherwise deactivate instead. */
    public function delete(Member $member): void
    {
        DB::transaction(function () use ($member) {
            $locked = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();

            if ($locked->transactions()->exists()) {
                throw new MembershipException(
                    'This member has transaction history and cannot be deleted. Deactivate the member instead.'
                );
            }

            // Soft delete does not trigger the users.member_id RESTRICT foreign key, so guard it here.
            if ($locked->account()->exists()) {
                throw new MembershipException(
                    'This member has a login account and cannot be deleted. Deactivate the member instead.'
                );
            }

            $locked->delete();
        });
    }

    // =====================================================================
    // Eligibility
    // =====================================================================

    /** Why a new application cannot be opened, or null when it can. */
    public function applicationBlocker(Member $member): ?string
    {
        if ($member->trashed()) {
            return 'This member record has been deleted.';
        }
        if ($member->status === MemberStatus::Inactive) {
            return 'The member is inactive. Reactivate the member first.';
        }
        if ($this->hasOpenTransaction($member)) {
            return 'The member already has an open (pending or paid) transaction.';
        }
        if ($member->status !== MemberStatus::Pending || $this->hasEverBeenApproved($member)) {
            return 'The member already has a membership on record. Use a renewal instead.';
        }

        return null;
    }

    /** Why a renewal cannot be opened, or null when it can. */
    public function renewalBlocker(Member $member): ?string
    {
        if ($member->trashed()) {
            return 'This member record has been deleted.';
        }
        if ($member->status === MemberStatus::Inactive) {
            return 'The member is inactive. Reactivate the member first.';
        }
        if ($member->status === MemberStatus::Pending || $member->membership_end === null) {
            return 'The member has no approved membership yet. Process the application first.';
        }
        if ($this->hasOpenTransaction($member)) {
            return 'The member already has an open (pending or paid) transaction.';
        }
        if ($member->status === MemberStatus::Active && $member->membership_end->gt($this->renewalWindowOpensUntil())) {
            $opens = $member->membership_end->copy()->subDays($this->renewalWindowDays());

            return 'Renewal opens '.$this->renewalWindowDays().' days before expiry (from '
                .$opens->format('M j, Y').'). The membership is valid until '.$member->membership_end->format('M j, Y').'.';
        }

        return null;
    }

    public function renewalWindowDays(): int
    {
        return (int) config('memberflow.renewal_window_days', 60);
    }

    // =====================================================================
    // Opening transactions
    // =====================================================================

    /** Open a pending "application" transaction priced at the member's current level. */
    public function openApplication(Member $member, ?User $createdBy, ?string $remarks = null): MembershipTransaction
    {
        return DB::transaction(function () use ($member, $createdBy, $remarks) {
            // Lock the member row: two requests cannot open two transactions at once.
            $locked = Member::withTrashed()->whereKey($member->id)->lockForUpdate()->firstOrFail();

            if ($blocker = $this->applicationBlocker($locked)) {
                throw new MembershipException($blocker);
            }

            $level = MembershipLevel::findOrFail($locked->membership_level_id);

            return $this->createTransaction($locked, $level, TransactionType::Application, $createdBy, $remarks);
        });
    }

    /**
     * Open a pending "renewal" transaction. The member may renew at the same or
     * a different (active) level; the level's fee and validity are snapshotted.
     */
    public function openRenewal(Member $member, MembershipLevel $level, ?User $createdBy, ?string $remarks = null): MembershipTransaction
    {
        return DB::transaction(function () use ($member, $level, $createdBy, $remarks) {
            $locked = Member::withTrashed()->whereKey($member->id)->lockForUpdate()->firstOrFail();

            if ($blocker = $this->renewalBlocker($locked)) {
                throw new MembershipException($blocker);
            }

            $level = MembershipLevel::whereKey($level->id)->first();
            if (! $level || ! $level->is_active) {
                throw new MembershipException('Choose an active membership level for the renewal.');
            }

            return $this->createTransaction($locked, $level, TransactionType::Renewal, $createdBy, $remarks);
        });
    }

    // =====================================================================
    // Approval, cancellation, expiry
    // =====================================================================

    /** Why the transaction cannot be approved now, or null when it can. */
    public function approvalBlocker(MembershipTransaction $transaction, ?Member $member = null): ?string
    {
        $member ??= $transaction->member;

        if ($transaction->status === TransactionStatus::Approved) {
            return 'This transaction has already been approved.';
        }
        if (! $transaction->status->canTransitionTo(TransactionStatus::Approved)) {
            return 'Only fully paid transactions can be approved. This one is '.strtolower($transaction->status->label()).'.';
        }
        if ($transaction->balanceCents() !== 0) {
            return 'The transaction still has an outstanding balance of '.$transaction->formattedBalance().'.';
        }
        if (! $member || $member->trashed()) {
            return 'The member record has been deleted.';
        }
        if ($member->status === MemberStatus::Inactive) {
            return 'The member is inactive. Reactivate the member before approving.';
        }
        if ($member->source === MemberSource::Online && ! $member->isEmailVerified()) {
            return 'This online applicant has not verified their email address yet. Resend the verification email and approve once it is verified.';
        }

        return null;
    }

    /**
     * Approve a fully paid transaction and activate/extend the membership.
     */
    public function approve(MembershipTransaction $transaction, User $approver): MembershipTransaction
    {
        return DB::transaction(function () use ($transaction, $approver) {
            // Lock both rows. Re-reading under the lock defeats stale models and double clicks.
            $txn = MembershipTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $member = Member::withTrashed()->whereKey($txn->member_id)->lockForUpdate()->firstOrFail();

            if ($blocker = $this->approvalBlocker($txn, $member)) {
                throw new MembershipException($blocker);
            }

            $today = CarbonImmutable::today();
            $continuing = $txn->type === TransactionType::Renewal
                && $member->membership_end !== null
                && $member->membership_end->gte($today);

            $start = $continuing ? CarbonImmutable::parse($member->membership_end)->addDay() : $today;
            $end = $this->periodEnd($start, $txn->validity_months);

            $txn->forceFill([
                'status' => TransactionStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $approver->id,
                'period_start' => $start,
                'period_end' => $end,
            ])->save();

            $member->forceFill([
                'status' => MemberStatus::Active,
                'membership_level_id' => $txn->membership_level_id,
                // A continuous renewal keeps the original start of the current membership.
                'membership_start' => $continuing && $member->membership_start ? $member->membership_start : $start,
                'membership_end' => $end,
            ])->save();

            TransactionApproved::dispatch($txn);

            return $txn;
        });
    }

    /** Why the transaction cannot be cancelled, or null when it can. */
    public function cancellationBlocker(MembershipTransaction $transaction): ?string
    {
        if (! $transaction->status->canTransitionTo(TransactionStatus::Cancelled)) {
            return 'Only pending transactions can be cancelled. This one is '.strtolower($transaction->status->label()).'.';
        }
        if ($transaction->paidCents() > 0) {
            return 'Payments have been recorded on this transaction, so it cannot be cancelled.';
        }

        return null;
    }

    /**
     * Cancel an unpaid pending transaction. The row is kept for history.
     * Rejecting an online application also deactivates the online applicant.
     */
    public function cancel(MembershipTransaction $transaction, User $by, string $reason): MembershipTransaction
    {
        return DB::transaction(function () use ($transaction, $by, $reason) {
            $txn = MembershipTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($blocker = $this->cancellationBlocker($txn)) {
                throw new MembershipException($blocker);
            }

            $txn->forceFill([
                'status' => TransactionStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancellation_reason' => $reason,
            ])->save();

            $member = Member::withTrashed()->whereKey($txn->member_id)->lockForUpdate()->first();
            if ($member
                && $txn->type === TransactionType::Application
                && $member->source === MemberSource::Online
                && $member->status === MemberStatus::Pending) {
                $member->forceFill(['status' => MemberStatus::Inactive])->save();
            }

            TransactionCancelled::dispatch($txn);

            return $txn;
        });
    }

    /**
     * Expire everything whose validity has ended (period_end before today).
     *
     * - Approved transactions whose own period has ended become "expired".
     * - Active members whose membership_end has passed become "expired".
     *   Approving a renewal moves membership_end forward, so a member with an
     *   approved renewal is never expired while the renewed period runs.
     *
     * @return array{transactions: int, members: int}
     */
    public function expireDue(): array
    {
        return DB::transaction(function () {
            $today = today()->toDateString();

            $transactions = MembershipTransaction::query()
                ->where('status', TransactionStatus::Approved->value)
                ->whereNotNull('period_end')
                ->where('period_end', '<', $today)
                ->update(['status' => TransactionStatus::Expired->value, 'updated_at' => now()]);

            $members = Member::query()
                ->where('status', MemberStatus::Active->value)
                ->whereNotNull('membership_end')
                ->where('membership_end', '<', $today)
                ->update(['status' => MemberStatus::Expired->value, 'updated_at' => now()]);

            return ['transactions' => $transactions, 'members' => $members];
        });
    }

    /** Inclusive end date: start + N months − 1 day (no month overflow). */
    public function periodEnd(CarbonImmutable $start, int $months): CarbonImmutable
    {
        return $start->addMonthsNoOverflow($months)->subDay();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    public function hasOpenTransaction(Member $member): bool
    {
        return $member->transactions()
            ->whereIn('status', [TransactionStatus::Pending->value, TransactionStatus::Paid->value])
            ->exists();
    }

    public function statusFromDates(Member $member): MemberStatus
    {
        if ($member->membership_end === null) {
            return MemberStatus::Pending;
        }

        return $member->membership_end->lt(today()) ? MemberStatus::Expired : MemberStatus::Active;
    }

    private function hasEverBeenApproved(Member $member): bool
    {
        return $member->transactions()
            ->whereIn('status', [TransactionStatus::Approved->value, TransactionStatus::Expired->value])
            ->exists();
    }

    private function renewalWindowOpensUntil(): Carbon
    {
        return today()->addDays($this->renewalWindowDays());
    }

    /**
     * Insert a transaction with fee/validity snapshots, then assign its reference.
     * A zero-fee level has nothing to pay, so the transaction starts as "paid".
     */
    private function createTransaction(
        Member $member,
        MembershipLevel $level,
        TransactionType $type,
        ?User $createdBy,
        ?string $remarks,
    ): MembershipTransaction {
        $transaction = new MembershipTransaction(['remarks' => $remarks]);
        $transaction->forceFill([
            'member_id' => $member->id,
            'membership_level_id' => $level->id,
            'type' => $type,
            'amount' => $level->fee,
            'validity_months' => $level->validity_months,
            'amount_paid' => '0.00',
            'status' => Money::fromDecimal($level->fee) === 0 ? TransactionStatus::Paid : TransactionStatus::Pending,
            'created_by' => $createdBy?->id,
        ])->save();

        $transaction->forceFill([
            'reference_no' => NumberGenerator::transaction($transaction->id, $transaction->created_at),
        ])->save();

        // Notification hook; delivered only if the surrounding DB transaction commits.
        TransactionOpened::dispatch($transaction);

        return $transaction;
    }

    /**
     * Coordinates are stored ONLY with explicit consent ($data['location_consent']).
     * Request validation already requires consent when coordinates are given; this is
     * the defensive second line: without consent the coordinates are discarded.
     * Consent time is recorded when coordinates are first given or changed, and
     * cleared together with the coordinates.
     */
    private function applyLocationConsent(Member $member, array $data): void
    {
        $consented = filter_var($data['location_consent'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($member->latitude === null || $member->longitude === null || ! $consented) {
            $member->forceFill(['latitude' => null, 'longitude' => null, 'location_consent_at' => null]);

            return;
        }

        if ($member->location_consent_at === null || $member->isDirty(['latitude', 'longitude'])) {
            $member->forceFill(['location_consent_at' => now()]);
        }
    }

    private function assertLevelCanChange(Member $member): void
    {
        if ($member->status !== MemberStatus::Pending) {
            throw new MembershipException(
                'The level of an active, expired or inactive membership is changed through a renewal, not by editing.'
            );
        }

        $paidSomething = $member->transactions()
            ->where('amount_paid', '>', 0)
            ->whereIn('status', [TransactionStatus::Pending->value, TransactionStatus::Paid->value])
            ->exists();

        if ($paidSomething) {
            throw new MembershipException(
                'Payments have already been recorded for this application, so its level can no longer be changed.'
            );
        }
    }

    private function repriceOpenApplication(Member $member): void
    {
        $level = MembershipLevel::findOrFail($member->membership_level_id);

        $member->transactions()
            ->whereIn('status', [TransactionStatus::Pending->value, TransactionStatus::Paid->value])
            ->where('type', TransactionType::Application->value)
            ->where('amount_paid', 0)
            ->lockForUpdate()
            ->get()
            ->each(fn (MembershipTransaction $transaction) => $transaction->forceFill([
                'membership_level_id' => $level->id,
                'amount' => $level->fee,
                'validity_months' => $level->validity_months,
                // A zero-fee level has nothing left to pay.
                'status' => Money::fromDecimal($level->fee) === 0 ? TransactionStatus::Paid : TransactionStatus::Pending,
            ])->save());
    }
}
