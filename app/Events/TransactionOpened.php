<?php

namespace App\Events;

use App\Models\MembershipTransaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** An application or renewal transaction was opened (dispatched after commit). */
class TransactionOpened implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly MembershipTransaction $transaction) {}
}
