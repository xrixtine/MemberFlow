<?php

namespace App\Events;

use App\Models\MembershipTransaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A fully paid transaction was approved and the membership activated/extended (after commit). */
class TransactionApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly MembershipTransaction $transaction) {}
}
