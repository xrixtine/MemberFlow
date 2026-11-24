<?php

namespace App\Services;

use App\Enums\InvitationStatus;
use App\Enums\UserRole;
use App\Exceptions\AccountException;
use App\Exceptions\EmailDeliveryException;
use App\Models\EmailDelivery;
use App\Models\Member;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Models\UserInvitation;
use App\Notifications\AdminEmailVerificationCode;
use App\Support\OneTimeCode;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Changes an EXISTING administrator's sign-in email and password
 * (php artisan memberflow:change-admin-credentials). Never creates an account and
 * never changes the user id, role, active status or any related record.
 *
 *  1. problems()   – every precondition, checked before anything is sent or changed.
 *  2. sendCode()   – a one-time code to the NEW address only, sent immediately.
 *  3. apply()      – one locked transaction: email, password, verification time,
 *                    remember token, two audit rows and deletion of every session of
 *                    that user. Any failure rolls all of it back.
 */
class AdminCredentialService
{
    /** Test seam: replaces OneTimeCode::generate() so tests can type the code. */
    public ?Closure $codeGenerator = null;

    public function __construct(private readonly EmailDeliveryTracker $tracker) {}

    public static function normalizeEmail(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    /**
     * Every reason the change cannot go ahead (empty = ready). Nothing is changed.
     *
     * @return list<string>
     */
    public function problems(?User $user, string $currentEmail, string $newEmail): array
    {
        if ($user === null) {
            return ['No user account has that id.'];
        }

        $problems = [];

        if ($user->role !== UserRole::Admin) {
            $problems[] = "User #{$user->id} is not an administrator. This command only changes an existing administrator.";
        }
        if (! $user->is_active) {
            $problems[] = "User #{$user->id} is deactivated.";
        }
        if ($user->member_id !== null) {
            $problems[] = "User #{$user->id} is linked to a membership record.";
        }
        if ($currentEmail === '' || $currentEmail !== self::normalizeEmail($user->email)) {
            $problems[] = "--current-email does not match the current sign-in email of user #{$user->id} (it may already have been changed). Nothing was done.";
        }

        $problems = array_merge($problems, $this->newEmailProblems($user, $newEmail), $this->environmentProblems());

        return $problems;
    }

    /** @return list<string> */
    private function newEmailProblems(User $user, string $newEmail): array
    {
        $validator = Validator::make(['email' => $newEmail], ['email' => ['required', 'string', 'email:rfc', 'max:100']]);
        if ($validator->fails()) {
            return ['The new email address is not a valid email address (at most 100 characters).'];
        }

        $problems = [];
        if ($newEmail === self::normalizeEmail($user->email)) {
            $problems[] = 'The new email address is already this administrator\'s sign-in email. Nothing to do.';
        }
        if ($this->tracker->isFictional($newEmail)) {
            $problems[] = 'The new email address uses a reserved/fictional domain and cannot receive the confirmation code.';
        }
        if (User::whereRaw('LOWER(email) = ?', [$newEmail])->whereKeyNot($user->id)->exists()) {
            $problems[] = 'The new email address is already used by another MemberFlow account.';
        }
        if (Member::withTrashed()->whereRaw('LOWER(email) = ?', [$newEmail])->exists()) {
            $problems[] = 'The new email address belongs to a membership record. Use an address that no member uses.';
        }
        if (UserInvitation::whereRaw('LOWER(email) = ?', [$newEmail])->where('status', InvitationStatus::Pending->value)->exists()) {
            $problems[] = 'The new email address has a pending invitation. Revoke it first.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function environmentProblems(): array
    {
        $problems = [];

        if ($this->tracker->currentMailer() !== 'smtp') {
            $problems[] = 'MAIL_MAILER is not "smtp": the confirmation code could not be delivered (and log mode would write it to a file).';
        } elseif ($this->tracker->smtpMissingCredentials()) {
            $problems[] = 'MAIL_USERNAME or MAIL_PASSWORD is empty, so the confirmation code cannot be sent.';
        }

        $connection = config('session.connection');
        if (config('session.driver') !== 'database'
            || ($connection !== null && $connection !== config('database.default'))
            || ! Schema::hasTable($this->sessionTable())) {
            $problems[] = 'Sessions are not stored in the application database (SESSION_DRIVER=database), so existing sessions could not be signed out safely.';
        }

        return $problems;
    }

    public function sessionTable(): string
    {
        return (string) config('session.table', 'sessions');
    }

    public function activeSessionCount(User $user): int
    {
        return Schema::hasTable($this->sessionTable())
            ? DB::table($this->sessionTable())->where('user_id', $user->id)->count()
            : 0;
    }

    /** @return array{0: OneTimeCode, 1: string} */
    public function issueCode(): array
    {
        return OneTimeCode::issue(
            (int) config('memberflow.admin_credentials.code_ttl_minutes', 15),
            (int) config('memberflow.admin_credentials.max_attempts', 3),
            $this->codeGenerator,
        );
    }

    /**
     * Email the code to the new address ONLY (never to the account's current address).
     *
     * @throws EmailDeliveryException when it was not accepted by the SMTP server
     */
    public function sendCode(User $user, string $newEmail, string $code): EmailDelivery
    {
        return $this->tracker->sendNowOrFail(
            Notification::route('mail', $newEmail),
            new AdminEmailVerificationCode($code, (int) config('memberflow.admin_credentials.code_ttl_minutes', 15)),
            $user,
        );
    }

    /**
     * Apply the change atomically. The account must still look exactly as it did when the
     * command started (same email and password hash), otherwise nothing is changed.
     *
     * @return int number of sessions signed out
     *
     * @throws AccountException|\Throwable nothing is changed when anything fails
     */
    public function apply(int $userId, string $expectedEmail, string $expectedPasswordHash, string $newEmail, string $newPassword): int
    {
        return DB::transaction(function () use ($userId, $expectedEmail, $expectedPasswordHash, $newEmail, $newPassword) {
            $user = User::whereKey($userId)->lockForUpdate()->first();

            if ($user === null || $user->role !== UserRole::Admin || ! $user->is_active || $user->member_id !== null) {
                throw new AccountException('The account is no longer an active administrator. Nothing was changed.');
            }
            if ($user->email !== $expectedEmail || ! hash_equals($expectedPasswordHash, (string) $user->getAuthPassword())) {
                throw new AccountException('The account was changed by someone else while this command was running. Nothing was changed.');
            }
            if (User::whereRaw('LOWER(email) = ?', [$newEmail])->whereKeyNot($userId)->lockForUpdate()->exists()) {
                throw new AccountException('The new email address was taken by another account meanwhile. Nothing was changed.');
            }

            $activeAdminsBefore = User::admins()->where('is_active', true)->count();

            $user->forceFill([
                'email' => $newEmail,
                'password' => $newPassword,                   // hashed by the model's "hashed" cast
                'email_verified_at' => now(),
                'remember_token' => Str::random(60),          // every "remember me" cookie stops working
            ])->save();

            $signedOut = $this->invalidateSessions($userId);

            UserAccountEvent::create([
                'user_id' => $userId, 'actor_id' => null, 'type' => UserAccountEvent::EMAIL_CHANGED,
                'old_value' => $expectedEmail, 'new_value' => $newEmail,
                'note' => 'Changed with php artisan memberflow:change-admin-credentials after the one-time code emailed to the new address was confirmed.',
            ]);
            UserAccountEvent::create([
                'user_id' => $userId, 'actor_id' => null, 'type' => UserAccountEvent::PASSWORD_CHANGED,
                'note' => "Changed at a hidden command-line prompt. {$signedOut} session(s) signed out; remember-me token replaced.",
            ]);

            if (User::admins()->where('is_active', true)->count() !== $activeAdminsBefore) {
                throw new AccountException('The number of active administrators changed unexpectedly. Nothing was changed.');
            }

            return $signedOut;
        });
    }

    /** Delete every stored session of the user (part of apply()'s transaction). */
    public function invalidateSessions(int $userId): int
    {
        return DB::table($this->sessionTable())->where('user_id', $userId)->delete();
    }
}
