<?php

namespace App\Notifications\Concerns;

use App\Models\EmailDelivery;
use App\Models\MembershipTransaction;

/** Membership notifications are rebuilt from the related transaction for a manual retry. */
trait RebuildsFromTransaction
{
    public static function rebuild(EmailDelivery $delivery): ?self
    {
        $transaction = $delivery->related_type === 'transaction'
            ? MembershipTransaction::find($delivery->related_id)
            : null;

        return $transaction ? new self($transaction) : null;
    }
}
