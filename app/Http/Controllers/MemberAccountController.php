<?php

namespace App\Http\Controllers;

use App\Exceptions\AccountException;
use App\Models\EmailDelivery;
use App\Models\Member;
use App\Models\UserInvitation;
use App\Services\EmailDeliveryTracker;
use App\Services\MemberAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Administrator actions on a member's LOGIN account (role:admin routes).
 * Resend/revoke of an activation invitation reuse InvitationController.
 */
class MemberAccountController extends Controller
{
    public function __construct(
        private readonly MemberAccountService $accounts,
        private readonly EmailDeliveryTracker $tracker,
    ) {}

    /** Send an activation invitation to the member's current email address. */
    public function invite(Request $request, Member $member): RedirectResponse
    {
        $this->authorize('create', UserInvitation::class);

        try {
            $invitation = $this->accounts->invite($member, $request->user());
        } catch (AccountException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Activation invitation issued to {$invitation->email}. It expires {$invitation->expires_at->format('M j, Y g:i A')}.")
            ->with('warning', $this->notice(EmailDelivery::where('related_type', 'invitation')->where('related_id', $invitation->id)->latest('id')->first(), 'The activation email'));
    }

    /** Re-send the sign-in email change confirmation to the member's new address. */
    public function resendEmailConfirmation(Request $request, Member $member): RedirectResponse
    {
        $this->authorize('create', UserInvitation::class);

        $account = $member->account;
        if (! $account) {
            return back()->with('error', 'This member has no login account.');
        }

        try {
            $delivery = $this->accounts->sendEmailChangeConfirmation($account, $member);
        } catch (AccountException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Confirmation link sent to {$member->email}. The sign-in email changes only after the member confirms it while signed in.")
            ->with('warning', $this->notice($delivery, 'The confirmation email'));
    }

    private function notice(?EmailDelivery $delivery, string $what): ?string
    {
        if (! $delivery) {
            return null;
        }

        return $this->tracker->isLogMode() || $delivery->status->value === 'skipped' ? $this->tracker->describe($delivery, $what) : null;
    }
}
