<?php

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\StaffInvitationIssued;
use App\Notifications\MemberAccountInvitation;
use App\Notifications\StaffInvitation;
use App\Services\EmailDeliveryTracker;
use Illuminate\Support\Facades\Notification;

/**
 * Emails the activation link when an invitation is issued or resent (after commit).
 * The invitee has no account yet, so the email is routed to the invited address.
 * The email type follows the role STORED on the invitation (staff or member).
 */
class SendStaffInvitationEmail
{
    public function __construct(private readonly EmailDeliveryTracker $tracker) {}

    public function handle(StaffInvitationIssued $event): void
    {
        $notification = $event->invitation->role === UserRole::Member
            ? new MemberAccountInvitation($event->invitation, $event->rawToken, $event->isResend)
            : new StaffInvitation($event->invitation, $event->rawToken, $event->isResend);

        $this->tracker->send(Notification::route('mail', $event->invitation->email), $notification, $event->invitation);
    }
}
