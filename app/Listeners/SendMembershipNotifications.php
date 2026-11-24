<?php

namespace App\Listeners;

use App\Enums\MemberSource;
use App\Enums\TransactionType;
use App\Events\TransactionApproved;
use App\Events\TransactionCancelled;
use App\Events\TransactionOpened;
use App\Models\MembershipTransaction;
use App\Notifications\MembershipApplicationReceived;
use App\Notifications\MembershipApproved;
use App\Notifications\MembershipRenewalSubmitted;
use App\Notifications\MembershipTransactionCancelled;
use App\Notifications\TrackedNotification;
use App\Services\EmailDeliveryTracker;

/**
 * Membership emails. Each business event emails the member at most once
 * (dedupe key per transaction), so retries and double submits never duplicate.
 *
 * Online applications are acknowledged by the verification email itself
 * (VerifyApplicantEmail), so they do not also get "application received".
 */
class SendMembershipNotifications
{
    public function __construct(private readonly EmailDeliveryTracker $tracker) {}

    public function handleOpened(TransactionOpened $event): void
    {
        $txn = $event->transaction->loadMissing('member');

        if ($txn->type === TransactionType::Renewal) {
            $this->notify($txn, new MembershipRenewalSubmitted($txn), 'renewal-submitted');
        } elseif ($txn->member?->source !== MemberSource::Online) {
            $this->notify($txn, new MembershipApplicationReceived($txn), 'application-received');
        }
    }

    public function handleApproved(TransactionApproved $event): void
    {
        $this->notify($event->transaction, new MembershipApproved($event->transaction), 'membership-approved');
    }

    public function handleCancelled(TransactionCancelled $event): void
    {
        $this->notify($event->transaction, new MembershipTransactionCancelled($event->transaction), 'transaction-cancelled');
    }

    private function notify(MembershipTransaction $txn, TrackedNotification $notification, string $key): void
    {
        $member = $txn->member()->withTrashed()->first();
        if (! $member || $member->trashed()) {
            return;
        }

        $this->tracker->send($member, $notification, $txn, "{$key}:txn:{$txn->id}");
    }
}
