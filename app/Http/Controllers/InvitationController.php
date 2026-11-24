<?php

namespace App\Http\Controllers;

use App\Enums\EmailDeliveryStatus;
use App\Enums\InvitationStatus;
use App\Exceptions\AccountException;
use App\Models\EmailDelivery;
use App\Models\UserInvitation;
use App\Services\EmailDeliveryTracker;
use App\Services\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Administrator side of account invitations: list, details, resend and revoke.
 * New invitations are member-account activations issued from a member's page
 * (MemberAccountController); staff invitations were retired in R6 and remain as
 * history only. Raw tokens are never shown here.
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly EmailDeliveryTracker $tracker,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', UserInvitation::class);

        $q = trim((string) $request->query('q', ''));
        $status = InvitationStatus::tryFrom((string) $request->query('status'));

        $invitations = UserInvitation::query()
            ->with(['inviter', 'acceptedUser'])
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('email', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%')
                ->orWhere('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%')))
            // Filter on the EFFECTIVE status: pending rows past their expiry count as expired.
            ->when($status === InvitationStatus::Pending, fn ($query) => $query->where('status', 'pending')->where('expires_at', '>=', now()))
            ->when($status === InvitationStatus::Expired, fn ($query) => $query->where(fn ($w) => $w->where('status', 'expired')
                ->orWhere(fn ($p) => $p->where('status', 'pending')->where('expires_at', '<', now()))))
            ->when(in_array($status, [InvitationStatus::Accepted, InvitationStatus::Revoked], true), fn ($query) => $query->where('status', $status->value))
            ->latest()
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('invitations.index', compact('invitations', 'q', 'status'));
    }

    public function show(UserInvitation $invitation): View
    {
        $this->authorize('view', $invitation);

        $invitation->load(['inviter', 'acceptedUser', 'revoker']);

        $deliveries = EmailDelivery::where('related_type', $invitation->getMorphClass())
            ->where('related_id', $invitation->id)
            ->latest()->latest('id')
            ->get();

        return view('invitations.show', compact('invitation', 'deliveries'));
    }

    public function resend(Request $request, UserInvitation $invitation): RedirectResponse
    {
        $this->authorize('resend', $invitation);

        try {
            $this->invitations->resend($invitation, $request->user());
        } catch (AccountException $e) {
            return back()->with('error', $e->getMessage());
        }

        $invitation->refresh();

        return back()
            ->with('success', "Invitation resent with a new link (the previous link no longer works). New expiry: {$invitation->expires_at->format('M j, Y g:i A')}.")
            ->with('warning', $this->deliveryNotice($invitation));
    }

    public function revoke(Request $request, UserInvitation $invitation): RedirectResponse
    {
        $this->authorize('revoke', $invitation);

        try {
            $this->invitations->revoke($invitation, $request->user());
        } catch (AccountException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Invitation for {$invitation->email} revoked. Its link no longer works.");
    }

    /**
     * Honest delivery status of the invitation email just created (never "delivered"
     * unless it is). In log mode or for a fictional address, point to the
     * development-only CLI, which prints a fresh link to the terminal only.
     */
    private function deliveryNotice(UserInvitation $invitation): string
    {
        $delivery = $this->latestDelivery($invitation);
        if (! $delivery) {
            return 'No invitation email was queued.';
        }

        $notice = $this->tracker->describe($delivery, 'The invitation email');

        if ($delivery->status === EmailDeliveryStatus::Skipped || $this->tracker->isLogMode()) {
            $notice .= " The activation link is never written to logs. For local testing, run: php artisan memberflow:invitation-link {$invitation->email}";
        }

        return $notice;
    }

    private function latestDelivery(UserInvitation $invitation): ?EmailDelivery
    {
        return EmailDelivery::where('related_type', $invitation->getMorphClass())
            ->where('related_id', $invitation->id)
            ->latest()->latest('id')
            ->first();
    }
}
