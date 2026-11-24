<?php

namespace App\Services;

use App\Enums\MemberSource;
use App\Exceptions\AccountException;
use App\Models\EmailDelivery;
use App\Models\Member;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Models\UserInvitation;
use App\Notifications\ConfirmAccountEmailChange;
use App\Support\AppUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Member login accounts (R3): activation invitations and keeping the account's
 * sign-in email in step with its membership record.
 *
 * Email synchronisation model — account ownership never moves silently:
 *  - The membership record's email is administrator-managed.
 *  - When it changes, pending activation invitations are revoked (they were sent
 *    to the old address) and old verification links stop working (they are bound
 *    to a hash of the email).
 *  - An EXISTING account keeps signing in with its current email until the member
 *    proves both things: they control the account (they must be signed in) and
 *    they control the new address (they open a signed link sent ONLY to it).
 *    Only then does users.email change.
 */
class MemberAccountService
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly EmailDeliveryTracker $tracker,
    ) {}

    // ---- Activation invitations ---------------------------------------------

    /** Administrator action: invite an existing member (walk-in or online) to activate a login. */
    public function invite(Member $member, User $admin): UserInvitation
    {
        [$invitation] = $this->invitations->issueForMember($member, $admin);

        return $invitation;
    }

    /**
     * Called right after an ONLINE applicant verifies their email for the first time.
     * Best effort: a problem here never undoes or blocks the verification itself.
     */
    public function inviteAfterVerification(Member $member): ?UserInvitation
    {
        if ($member->source !== MemberSource::Online || $this->invitations->memberInvitationBlocker($member) !== null) {
            return null;
        }

        try {
            [$invitation] = $this->invitations->issueForMember($member, null);

            return $invitation;
        } catch (Throwable $e) {
            Log::warning('Automatic member-account invitation was not issued.', [
                'member_id' => $member->id, 'reason' => class_basename($e).': '.$e->getMessage(),
            ]);

            return null;
        }
    }

    // ---- Email synchronisation --------------------------------------------------

    /** True while the account still signs in with an address the membership record no longer uses. */
    public function emailChangePending(Member $member, ?User $account = null): bool
    {
        $account ??= $member->account;

        return $account !== null && strcasecmp(trim($account->email), trim((string) $member->email)) !== 0;
    }

    /**
     * Must be called inside the transaction that saved a NEW email on $member
     * (MembershipService::update). Sends nothing before commit.
     */
    public function memberEmailChanged(Member $member, string $oldEmail, ?User $admin): void
    {
        $this->invitations->revokeOpenMemberInvitations($member, $admin, 'membership email changed');

        $account = User::where('member_id', $member->id)->lockForUpdate()->first();
        if (! $account) {
            return;
        }

        if (! $this->emailChangePending($member, $account)) {
            // Changed back to the address the account already proved: it is verified again.
            $member->forceFill(['email_verified_at' => $account->email_verified_at ?? now()])->save();

            return;
        }

        UserAccountEvent::create([
            'user_id' => $account->id, 'actor_id' => $admin?->id, 'type' => UserAccountEvent::EMAIL_CHANGE_REQUESTED,
            'old_value' => mb_substr($account->email, 0, 100), 'new_value' => mb_substr((string) $member->email, 0, 100),
            'note' => 'Membership email changed. Sign-in email stays the same until the member confirms the new address.',
        ]);

        $this->sendEmailChangeConfirmation($account, $member);
    }

    /** Email the confirmation link to the membership record's (new) address only. */
    public function sendEmailChangeConfirmation(User $account, ?Member $member = null): EmailDelivery
    {
        $member ??= $account->member;
        if (! $member || ! $this->emailChangePending($member, $account)) {
            throw new AccountException('There is no sign-in email change waiting for confirmation.', 'email');
        }

        return $this->tracker->send($member, new ConfirmAccountEmailChange($account, $this->confirmationUrl($account, $member)), $account);
    }

    public function confirmationUrl(User $account, Member $member): string
    {
        return AppUrl::to(URL::temporarySignedRoute(
            'account.email.confirm',
            now()->addHours((int) config('memberflow.verification_ttl_hours', 48)),
            ['user' => $account->id, 'hash' => $this->emailHash((string) $member->email)],
            absolute: false,
        ));
    }

    public function emailHash(string $email): string
    {
        return sha1(strtolower(trim($email)));
    }

    /**
     * Apply a confirmed change: the signed-in member opened the link sent to the
     * membership record's current email. Returns false when there was nothing to do.
     */
    public function confirmEmailChange(User $user, string $hash): bool
    {
        return DB::transaction(function () use ($user, $hash) {
            $member = Member::whereKey($user->member_id)->lockForUpdate()->first();
            $account = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! $member || ! $account->isMember() || (int) $account->member_id !== (int) $member->id) {
                throw new AccountException('This confirmation link is not valid for your account.', 'email');
            }
            if (! hash_equals($this->emailHash((string) $member->email), $hash)) {
                throw new AccountException('This confirmation link is out of date: the membership email has changed again. Use the newest email we sent.', 'email');
            }
            if (! $this->emailChangePending($member, $account)) {
                return false;
            }

            $newEmail = strtolower(trim((string) $member->email));
            if (User::where('email', $newEmail)->whereKeyNot($account->id)->exists()) {
                throw new AccountException('Another login account already uses this email address. Please contact the membership office.', 'email');
            }

            $oldEmail = $account->email;
            $account->forceFill(['email' => $newEmail, 'email_verified_at' => now()])->save();
            $member->forceFill(['email_verified_at' => now()])->save();

            UserAccountEvent::create([
                'user_id' => $account->id, 'actor_id' => $account->id, 'type' => UserAccountEvent::EMAIL_CHANGED,
                'old_value' => mb_substr($oldEmail, 0, 100), 'new_value' => mb_substr($newEmail, 0, 100),
                'note' => 'Confirmed by the member from the new address while signed in.',
            ]);

            return true;
        });
    }
}
