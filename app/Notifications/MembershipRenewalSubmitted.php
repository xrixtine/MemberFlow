<?php

namespace App\Notifications;

use App\Models\MembershipTransaction;
use App\Notifications\Concerns\RebuildsFromTransaction;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

/** A renewal transaction was opened for the member. */
class MembershipRenewalSubmitted extends TrackedNotification
{
    use RebuildsFromTransaction;

    public function __construct(public MembershipTransaction $transaction)
    {
        parent::__construct();
    }

    protected function buildMail(object $notifiable): MailMessage
    {
        $txn = $this->transaction->loadMissing(['member', 'level']);
        $member = $txn->member;

        $mail = (new MailMessage)
            ->subject('Your membership renewal has been submitted ('.$txn->reference_no.')')
            ->greeting("Hello {$member->first_name},")
            ->line('A renewal of your membership has been submitted.')
            ->line("Reference: {$txn->reference_no}")
            ->line("Level: {$txn->level->name} ({$txn->validity_months} months) — amount due ".Money::formatDecimal($txn->amount).'.');

        if ($member->membership_end) {
            $mail->line('Your current membership is valid until '.$member->membership_end->format('F j, Y').'. Once the renewal is paid and approved, the new period continues from your current end date (or from the approval date if it has already expired).');
        }

        return $mail->line('We will email you when the renewal is approved.');
    }
}
