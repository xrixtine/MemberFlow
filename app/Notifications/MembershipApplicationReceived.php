<?php

namespace App\Notifications;

use App\Models\MembershipTransaction;
use App\Notifications\Concerns\RebuildsFromTransaction;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

/** A walk-in member's application was recorded by the office. */
class MembershipApplicationReceived extends TrackedNotification
{
    use RebuildsFromTransaction;

    public function __construct(public MembershipTransaction $transaction)
    {
        parent::__construct();
    }

    protected function buildMail(object $notifiable): MailMessage
    {
        $txn = $this->transaction->loadMissing(['member', 'level']);

        return (new MailMessage)
            ->subject('We received your membership application ('.$txn->reference_no.')')
            ->greeting("Hello {$txn->member->first_name},")
            ->line('Your membership application has been recorded.')
            ->line("Reference: {$txn->reference_no}")
            ->line("Level: {$txn->level->name} — amount due ".Money::formatDecimal($txn->amount).', balance '.$txn->formattedBalance().'.')
            ->line('Your membership becomes active only after the application is fully paid and approved by an administrator. We will email you when it is approved.');
    }
}
