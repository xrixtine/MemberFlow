<?php

namespace App\Events;

use App\Models\UserInvitation;
use App\Support\AppUrl;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after an account invitation (staff, or member activation since R3) is
 * issued or resent, once the DB transaction commits. SendStaffInvitationEmail
 * emails the activation link that matches the invitation's stored role.
 *
 * $rawToken exists only in memory here; it is never stored, logged or shown in
 * the admin UI.
 */
class StaffInvitationIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly UserInvitation $invitation,
        #[\SensitiveParameter] public readonly string $rawToken,
        public readonly bool $isResend = false,
    ) {}

    public function activationUrl(): string
    {
        return AppUrl::route('invitation.show', $this->rawToken);
    }
}
