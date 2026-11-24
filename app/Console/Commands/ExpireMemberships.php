<?php

namespace App\Console\Commands;

use App\Services\MembershipService;
use Illuminate\Console\Command;

/**
 * Marks approved transactions and active memberships whose validity has
 * ended as "expired". Scheduled daily in routes/console.php; also available
 * to administrators as a button on the Transactions page.
 */
class ExpireMemberships extends Command
{
    protected $signature = 'memberflow:expire';

    protected $description = 'Expire memberships and approved transactions whose validity period has ended';

    public function handle(MembershipService $memberships): int
    {
        $result = $memberships->expireDue();

        $this->info("Expired {$result['transactions']} transaction(s) and {$result['members']} member(s).");

        return self::SUCCESS;
    }
}
