<?php

namespace App\Notifications;

use App\Models\MembershipTransaction;
use App\Notifications\Concerns\RebuildsFromTransaction;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * An application/renewal was cancelled by the office. The internal cancellation
 * reason is deliberately NOT included (it is an administrator note).
 */
class MembershipTransactionCancelled extends TrackedNotification
{
    use RebuildsFromTransaction;

    public function __construct(public MembershipTransaction $transaction)
    {
        parent::__construct();
    }

    protected function buildMail(object $notifiable): MailMessage
    {
        $txn = $this->transaction->loadMissing('member');

        return (new MailMessage)
            ->subject('Your membership '.strtolower($txn->type->label()).' was cancelled ('.$txn->reference_no.')')
            ->greeting("Hello {$txn->member->first_name},")
            ->line('Your membership '.strtolower($txn->type->label())." ({$txn->reference_no}) has been cancelled by the membership office.")
            ->line('No payment was recorded on it. If you believe this is a mistake or want to apply again, please contact the office.');
    }
}
