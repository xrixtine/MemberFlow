<?php

namespace App\Notifications;

use App\Enums\TransactionType;
use App\Models\MembershipTransaction;
use App\Notifications\Concerns\RebuildsFromTransaction;
use Illuminate\Notifications\Messages\MailMessage;

/** An application or renewal was approved; the membership is active. */
class MembershipApproved extends TrackedNotification
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
        $what = $txn->type === TransactionType::Renewal ? 'renewal' : 'membership application';

        $mail = (new MailMessage)
            ->subject('Your membership is approved ('.$txn->reference_no.')')
            ->greeting("Congratulations, {$member->first_name}!")
            ->line("Your {$what} ({$txn->reference_no}) has been approved.")
            ->line("Membership no.: {$member->membership_no}")
            ->line("Level: {$txn->level->name}");

        if ($txn->period_start && $txn->period_end) {
            $mail->line('Valid from '.$txn->period_start->format('F j, Y').' to '.$txn->period_end->format('F j, Y').'.');
        }

        return $mail->line('Thank you for being a member.');
    }
}
