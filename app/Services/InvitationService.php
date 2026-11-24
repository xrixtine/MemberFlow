<?php

namespace App\Services;

use App\Enums\InvitationStatus;
use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Events\StaffInvitationIssued;
use App\Exceptions\AccountException;
use App\Models\Member;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Models\UserInvitation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Member-account activation invitations: each is bound to one member record and
 * its current email and can only ever create a MEMBER account. Staff invitations
 * were retired in R6: none can be issued, resent or accepted; old rows remain as
 * history (accepted ones stay linked to the accounts they created).
 *
 * Security model:
 *  - Tokens are 64 characters from Str::random() (backed by random_bytes, a CSPRNG).
 *  - Only hash('sha256', token) is stored; the raw token lives only in the
 *    StaffInvitationIssued event (for the email link) and is never persisted.
 *  - Invitations expire after config('memberflow.invitation_ttl_hours') (72h).
 *  - Resending rotates the token: the previous link stops working immediately.
 *  - Acceptance locks the invitation row (SELECT ... FOR UPDATE) and re-checks it,
 *    so two simultaneous submissions cannot both create an account.
 *  - The role comes from the stored invitation, never from the request, and only
 *    member invitations can be accepted. No users row exists until acceptance,
 *    so nobody can log in before activating.
 */
class InvitationService
{
    public const RESEND_COOLDOWN_SECONDS = 60;

    public const STAFF_RETIRED_MESSAGE = 'Staff accounts were retired. This staff invitation can no longer be used; members activate their account with a member invitation.';

    // =====================================================================
    // Member-account invitations (R3)
    // =====================================================================

    /**
     * Why $member cannot receive a member-account invitation now, or null when it can.
     * Shown to administrators; never shown to the public.
     */
    public function memberInvitationBlocker(Member $member): ?string
    {
        return match (true) {
            $member->trashed() => 'This member record has been deleted.',
            $member->status === MemberStatus::Inactive => 'This member is deactivated. Reactivate the member first.',
            blank($member->email) => 'This member has no email address.',
            User::where('member_id', $member->id)->exists() => 'This member already has a login account.',
            User::where('email', $this->normalise($member->email))->exists() => 'Another login account already uses this email address. Change the member\'s email or resolve the other account first.',
            $this->openMemberInvitation($member) !== null => 'An activation invitation is already pending. Resend it instead.',
            default => null,
        };
    }

    /** The member's usable (pending, unexpired) activation invitation, if any. */
    public function openMemberInvitation(Member $member): ?UserInvitation
    {
        return UserInvitation::where('member_id', $member->id)
            ->where('status', InvitationStatus::Pending->value)
            ->where('expires_at', '>=', now())
            ->latest('id')->first();
    }

    /**
     * Issue a member-account activation invitation for an EXISTING member record.
     * $admin is null when MemberFlow issues it automatically (online applicant
     * who has just verified their email). The invitation is bound to the member
     * record AND its current email; it never grants anything but the member role.
     *
     * @return array{0: UserInvitation, 1: string}
     */
    public function issueForMember(Member $member, ?User $admin): array
    {
        [$invitation, $token] = DB::transaction(function () use ($member, $admin) {
            // Lock the member row first: serialises invitations and acceptance for this member.
            $locked = Member::withTrashed()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $email = $this->normalise((string) $locked->email);

            // Locking reads on this member's / this address's open invitations.
            $open = UserInvitation::where('status', InvitationStatus::Pending->value)
                ->where(fn ($q) => $q->where('member_id', $locked->id)->orWhere('email', $email))
                ->lockForUpdate()->get();
            $open->reject(fn (UserInvitation $i) => $i->isUsable())
                ->each(fn (UserInvitation $i) => $i->forceFill(['status' => InvitationStatus::Expired])->save());
            if ($open->contains(fn (UserInvitation $i) => $i->isUsable())) {
                throw new AccountException('An invitation is already pending for this member or email address. Resend it instead.', 'invitation');
            }

            if ($blocker = $this->memberInvitationBlocker($locked)) {
                throw new AccountException($blocker, 'invitation');
            }

            $token = $this->newToken();
            $invitation = new UserInvitation([
                'email' => $email,
                'name' => $locked->fullName(),
                'token_hash' => UserInvitation::hashToken($token),
                'expires_at' => now()->addHours($this->ttlHours()),
                'send_count' => 1,
                'last_sent_at' => now(),
            ]);
            $invitation->forceFill([
                'role' => UserRole::Member,        // fixed: an invitation can never grant more
                'member_id' => $locked->id,
                'invited_by' => $admin?->id,       // null = issued automatically by MemberFlow
                'status' => InvitationStatus::Pending,
            ])->save();

            return [$invitation, $token];
        });

        StaffInvitationIssued::dispatch($invitation, $token, false);

        return [$invitation, $token];
    }

    /** Revoke a member's pending activation invitations (e.g. their email changed). Returns how many. */
    public function revokeOpenMemberInvitations(Member $member, ?User $by, string $reason): int
    {
        $open = UserInvitation::where('member_id', $member->id)
            ->where('status', InvitationStatus::Pending->value)->lockForUpdate()->get();

        $open->each(fn (UserInvitation $i) => $i->forceFill([
            'status' => InvitationStatus::Revoked, 'revoked_at' => now(), 'revoked_by' => $by?->id,
        ])->save());

        if ($open->isNotEmpty()) {
            Log::info('Member activation invitation(s) revoked.', ['member_id' => $member->id, 'count' => $open->count(), 'reason' => $reason]);
        }

        return $open->count();
    }

    // =====================================================================
    // Shared
    // =====================================================================

    /** Rotate the token and extend the expiry. Allowed for pending or expired invitations. */
    public function resend(UserInvitation $invitation, ?User $admin): string
    {
        [$locked, $token] = DB::transaction(function () use ($invitation) {
            $locked = UserInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->effectiveStatus(), [InvitationStatus::Pending, InvitationStatus::Expired], true)) {
                throw new AccountException('Only pending or expired invitations can be resent. This one is '.strtolower($locked->effectiveStatus()->label()).'.', 'invitation');
            }
            if ($locked->last_sent_at && $locked->last_sent_at->gt(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))) {
                throw new AccountException('This invitation was sent less than a minute ago. Please wait before resending.', 'invitation');
            }
            if ($locked->role !== UserRole::Member) {
                throw new AccountException(self::STAFF_RETIRED_MESSAGE, 'invitation');
            }
            $this->assertEmailAvailable($locked->email);
            $this->assertMemberCanActivate($locked);

            $token = $this->newToken();
            $locked->forceFill([
                'token_hash' => UserInvitation::hashToken($token), // old link stops working
                'status' => InvitationStatus::Pending,
                'expires_at' => now()->addHours($this->ttlHours()),
                'send_count' => $locked->send_count + 1,
                'last_sent_at' => now(),
            ])->save();

            return [$locked, $token];
        });

        StaffInvitationIssued::dispatch($locked, $token, true);

        return $token;
    }

    public function revoke(UserInvitation $invitation, User $admin): UserInvitation
    {
        return DB::transaction(function () use ($invitation, $admin) {
            $locked = UserInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvitationStatus::Pending) {
                throw new AccountException('Only pending invitations can be revoked.', 'invitation');
            }

            $locked->forceFill([
                'status' => InvitationStatus::Revoked,
                'revoked_at' => now(),
                'revoked_by' => $admin->id,
            ])->save();

            return $locked;
        });
    }

    /** Look up an invitation by the raw token from a link (null when unknown). */
    public function findByToken(#[\SensitiveParameter] string $token): ?UserInvitation
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            return null;
        }

        return UserInvitation::where('token_hash', UserInvitation::hashToken($token))->first();
    }

    /** User-facing reason an invitation cannot be accepted, or null when it can. */
    public function acceptanceBlocker(?UserInvitation $invitation): ?string
    {
        return match (true) {
            $invitation === null => 'This invitation link is invalid. It may have been mistyped or replaced by a newer invitation.',
            $invitation->status === InvitationStatus::Accepted => 'This invitation has already been used. Sign in with your password instead.',
            $invitation->status === InvitationStatus::Revoked => 'This invitation has been revoked. Please contact your administrator.',
            $invitation->effectiveStatus() === InvitationStatus::Expired => 'This invitation has expired. Ask your administrator to resend it.',
            $invitation->role !== UserRole::Member => self::STAFF_RETIRED_MESSAGE,
            default => $this->memberActivationProblem($invitation),
        };
    }

    /**
     * Accept a member invitation and create the MEMBER account (linked to the
     * invitation's member record) with the chosen password. Staff invitations are
     * refused by acceptanceBlocker(). Single-use: the row is locked and re-validated
     * inside the transaction; the UNIQUE indexes on users.email and users.member_id
     * are the final backstop.
     */
    public function accept(#[\SensitiveParameter] string $token, ?string $name, #[\SensitiveParameter] string $password): User
    {
        try {
            return DB::transaction(fn () => $this->acceptLocked($token, $name, $password));
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created the account first: this attempt changes nothing.
            throw new AccountException('This invitation has already been used. Sign in with your password instead.', 'token');
        }
    }

    private function acceptLocked(#[\SensitiveParameter] string $token, ?string $name, #[\SensitiveParameter] string $password): User
    {
        $invitation = $this->findByToken($token);

        // Member invitations lock the member row FIRST (same order as issueForMember) to avoid deadlocks.
        if ($invitation?->member_id) {
            Member::withTrashed()->whereKey($invitation->member_id)->lockForUpdate()->first();
        }
        $locked = $invitation ? UserInvitation::whereKey($invitation->id)->lockForUpdate()->first() : null;

        // Re-check the token after locking (a resend may have rotated it meanwhile).
        if ($locked && ! hash_equals($locked->token_hash, UserInvitation::hashToken($token))) {
            $locked = null;
        }

        if ($blocker = $this->acceptanceBlocker($locked)) {
            throw new AccountException($blocker, 'token');
        }

        $this->assertEmailAvailable($locked->email);

        return $this->createMemberAccount($locked, $password);
    }

    private function createMemberAccount(UserInvitation $locked, #[\SensitiveParameter] string $password): User
    {
        $member = $this->assertMemberCanActivate($locked);

        // Name and email come from the membership record (administrator-managed), never from the form.
        $user = new User(['name' => $member->fullName(), 'email' => $locked->email, 'password' => $password]);
        $user->forceFill([
            'role' => UserRole::Member,
            'member_id' => $member->id,
            'is_active' => true,
            'email_verified_at' => now(),       // possession of the emailed link proves the address
            'invited_by' => $locked->invited_by,
        ])->save();

        // Opening the link sent to this address also proves ownership of the member's email.
        if (! $member->isEmailVerified()) {
            $member->forceFill(['email_verified_at' => now()])->save();
        }

        $this->markAccepted($locked, $user, 'Member account activated for '.($member->membership_no ?? 'member #'.$member->id).' via invitation #'.$locked->id.'.');

        return $user;
    }

    /**
     * A member invitation is only valid for its own member record, while that record
     * exists, is not deactivated, has no account yet and still has the SAME email
     * the invitation was sent to. Returns the user-facing problem, or null.
     */
    public function memberActivationProblem(UserInvitation $invitation): ?string
    {
        $member = $invitation->member_id ? Member::withTrashed()->whereKey($invitation->member_id)->first() : null;

        return match (true) {
            $member === null || $member->trashed() => 'This membership record is no longer available. Please contact the membership office.',
            $member->status === MemberStatus::Inactive => 'This membership is deactivated. Please contact the membership office.',
            strcasecmp(trim((string) $member->email), $invitation->email) !== 0 => 'The email address on this membership has changed, so this invitation is no longer valid. Ask the membership office for a new one.',
            User::where('member_id', $member->id)->exists() => 'This membership already has a login account. Sign in with your password instead.',
            default => null,
        };
    }

    private function assertMemberCanActivate(UserInvitation $invitation): Member
    {
        if ($problem = $this->memberActivationProblem($invitation)) {
            throw new AccountException($problem, 'token');
        }

        return Member::whereKey($invitation->member_id)->firstOrFail();
    }

    private function markAccepted(UserInvitation $locked, User $user, string $note): void
    {
        $locked->forceFill([
            'status' => InvitationStatus::Accepted,
            'accepted_at' => now(),
            'accepted_user_id' => $user->id,
        ])->save();

        UserAccountEvent::create([
            'user_id' => $user->id,
            'actor_id' => null,
            'type' => UserAccountEvent::ACTIVATED,
            'new_value' => $user->role->value,
            'note' => $note,
        ]);
    }

    /** Persist "expired" for pending invitations past their expiry (scheduled daily). */
    public function expireDue(): int
    {
        return UserInvitation::where('status', InvitationStatus::Pending->value)
            ->where('expires_at', '<', now())
            ->update(['status' => InvitationStatus::Expired->value, 'updated_at' => now()]);
    }

    public function ttlHours(): int
    {
        return (int) config('memberflow.invitation_ttl_hours', 72);
    }

    private function assertEmailAvailable(string $email): void
    {
        if (User::where('email', $email)->exists()) {
            throw new AccountException('A user account with this email address already exists.');
        }
    }

    private function normalise(string $email): string
    {
        return strtolower(trim($email));
    }

    private function newToken(): string
    {
        // Str::random uses random_bytes(); retry on the (astronomically unlikely) hash collision.
        do {
            $token = Str::random(64);
        } while (UserInvitation::where('token_hash', UserInvitation::hashToken($token))->exists());

        return $token;
    }
}
