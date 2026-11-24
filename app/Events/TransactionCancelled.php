<?php

namespace App\Events;

use App\Models\MembershipTransaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A pending transaction was cancelled (after commit). */
class TransactionCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly MembershipTransaction $transaction) {}
}
