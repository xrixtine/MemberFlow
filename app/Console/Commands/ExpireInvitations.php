<?php

namespace App\Console\Commands;

use App\Services\InvitationService;
use Illuminate\Console\Command;

/** Marks pending invitations past their 72-hour expiry as "expired" (scheduled daily). */
class ExpireInvitations extends Command
{
    protected $signature = 'memberflow:expire-invitations';

    protected $description = 'Mark pending account invitations past their expiry as expired';

    public function handle(InvitationService $invitations): int
    {
        $count = $invitations->expireDue();
        $this->info("Expired {$count} invitation(s).");

        return self::SUCCESS;
    }
}
