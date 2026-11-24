<?php

namespace App\Services;

use App\Enums\InvitationStatus;
use App\Enums\MemberStatus;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\Task;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * R6 — retire the staff role (memberflow:retire-staff).
 *
 * Every staff account must be named explicitly, either
 *   LINK   user → existing member record (role staff → member, users.member_id set), or
 *   RETIRE user  (role staff → legacy_staff: the row stays, it can never sign in).
 * Nothing else is changed: no password, email, name, activation flag, task, payment,
 * transaction, invitation or membership field. Each change writes a user_account_events
 * row tagged "[R6]" so the reversal can verify it is undoing exactly this migration.
 *
 * plan() is read-only. execute()/revert() lock the rows, re-validate the plan
 * inside the transaction and apply all-or-nothing.
 */
class StaffRetirementService
{
    public const TAG_LINK = '[R6] link';

    public const TAG_RETIRE = '[R6] retire';

    public const TAG_REVERT = '[R6] revert';

    /**
     * @param  array<int, string>  $links  user id => membership number
     * @param  list<int>  $retire  user ids
     * @param  list<int>  $allowNameMismatch  user ids whose name may differ from the member's
     * @return array{changes: list<array>, done: list<array>, errors: list<string>}
     */
    public function plan(array $links, array $retire, array $allowNameMismatch, bool $lock = false): array
    {
        $changes = $done = $errors = [];

        $overlap = array_intersect(array_keys($links), $retire);
        if ($overlap) {
            $errors[] = 'User(s) '.implode(', ', $overlap).' are listed both to link and to retire.';
        }
        foreach ($allowNameMismatch as $id) {
            if (! array_key_exists($id, $links)) {
                $errors[] = "--allow-name-mismatch={$id} is only valid for an account that is being linked with --link.";
            }
        }

        // Every remaining staff account must be covered, so nobody is silently locked out.
        $covered = array_merge(array_keys($links), $retire);
        $uncovered = User::where('role', UserRole::Staff->value)->whereNotIn('id', $covered ?: [0])->orderBy('id')->pluck('id')->all();
        if ($uncovered) {
            $errors[] = 'Staff account(s) not covered by --link or --retire: '.implode(', ', $uncovered).'. Every staff account must be linked or retired.';
        }

        foreach ($links as $userId => $membershipNo) {
            $user = $this->user($userId, $lock);
            $member = Member::withTrashed()->where('membership_no', $membershipNo)->when($lock, fn ($q) => $q->lockForUpdate())->first();

            if (! $user) {
                $errors[] = "User #{$userId} does not exist.";

                continue;
            }
            if (! $member) {
                $errors[] = "Member {$membershipNo} does not exist.";

                continue;
            }
            $row = ['action' => 'link', 'user' => $user, 'member' => $member];

            if ($user->role === UserRole::Member && (int) $user->member_id === (int) $member->id) {
                $done[] = $row;

                continue;
            }

            $problems = array_filter([
                $user->role !== UserRole::Staff ? "User #{$userId} is {$user->role->value}, not staff." : null,
                $member->trashed() ? "Member {$membershipNo} is deleted." : null,
                $member->status === MemberStatus::Inactive ? "Member {$membershipNo} is deactivated and cannot be linked." : null,
                ($other = User::where('member_id', $member->id)->whereKeyNot($userId)->first())
                    ? "Member {$membershipNo} already has a login account (user #{$other->id})." : null,
                strcasecmp(trim($user->email), trim((string) $member->email)) !== 0
                    ? "User #{$userId}'s email does not match member {$membershipNo}'s email (no override exists for this)." : null,
                $this->hasOpenInvitation($member, $user)
                    ? "There is a pending invitation for member {$membershipNo} or user #{$userId}'s email. Revoke it first." : null,
            ]);

            $nameMismatch = strcasecmp(trim($user->name), trim($member->fullName())) !== 0;
            if ($nameMismatch && ! in_array($userId, $allowNameMismatch, true)) {
                $problems[] = "User #{$userId} is named \"{$user->name}\" but member {$membershipNo} is \"{$member->fullName()}\". "
                    ."Link them only if this is intentional, with --allow-name-mismatch={$userId}.";
            }
            if (! $nameMismatch && in_array($userId, $allowNameMismatch, true)) {
                $problems[] = "--allow-name-mismatch={$userId} was given but the names match; remove it.";
            }

            $problems ? array_push($errors, ...$problems) : $changes[] = $row + ['name_mismatch' => $nameMismatch];
        }

        foreach ($retire as $userId) {
            $user = $this->user($userId, $lock);
            if (! $user) {
                $errors[] = "User #{$userId} does not exist.";

                continue;
            }
            $row = ['action' => 'retire', 'user' => $user, 'member' => null];

            if ($user->role === UserRole::LegacyStaff) {
                $done[] = $row;

                continue;
            }
            if ($user->role !== UserRole::Staff) {
                $errors[] = "User #{$userId} is {$user->role->value}, not staff; only staff accounts can be retired.";

                continue;
            }
            $open = Task::active()->where('assigned_to', $userId)->where('status', '!=', TaskStatus::Completed->value)->count();
            if ($open > 0) {
                $errors[] = "User #{$userId} still holds {$open} open task(s). Reassign them before retiring the account.";

                continue;
            }
            $changes[] = $row;
        }

        return ['changes' => $changes, 'done' => $done, 'errors' => $errors];
    }

    /** Apply the plan atomically. Returns the applied changes (empty when already done). */
    public function execute(array $links, array $retire, array $allowNameMismatch): array
    {
        return DB::transaction(function () use ($links, $retire, $allowNameMismatch) {
            $plan = $this->plan($links, $retire, $allowNameMismatch, lock: true);  // re-validated under row locks
            if ($plan['errors']) {
                throw new RuntimeException(implode(PHP_EOL, $plan['errors']));
            }

            foreach ($plan['changes'] as $change) {
                /** @var User $user */
                $user = $change['user'];
                if ($change['action'] === 'link') {
                    $member = $change['member'];
                    // Role and link change together (the database CHECK requires both). Nothing else changes.
                    $user->forceFill(['role' => UserRole::Member, 'member_id' => $member->id])->save();
                    $this->audit($user, UserRole::Staff, UserRole::Member, self::TAG_LINK
                        ." user #{$user->id} → member #{$member->id} ({$member->membership_no}) via memberflow:retire-staff."
                        .($change['name_mismatch'] ? ' Different names confirmed by the operator (--allow-name-mismatch).' : ''));
                } else {
                    $user->forceFill(['role' => UserRole::LegacyStaff])->save();
                    $this->audit($user, UserRole::Staff, UserRole::LegacyStaff, self::TAG_RETIRE
                        ." user #{$user->id} via memberflow:retire-staff. The account can no longer sign in; its history is kept.");
                }
            }

            return $plan['changes'];
        });
    }

    /**
     * Reversal plan (read-only unless $lock). Only undoes changes that are still exactly
     * as this migration left them, and whose latest role event is this migration's.
     *
     * @return array{changes: list<array>, done: list<array>, errors: list<string>}
     */
    public function revertPlan(array $links, array $retire, bool $lock = false): array
    {
        $changes = $done = $errors = [];

        foreach ($links + array_fill_keys($retire, null) as $userId => $membershipNo) {
            $action = $membershipNo === null ? 'retire' : 'link';
            $user = $this->user($userId, $lock);
            if (! $user) {
                $errors[] = "User #{$userId} does not exist.";

                continue;
            }
            $member = $membershipNo ? Member::withTrashed()->where('membership_no', $membershipNo)->first() : null;
            $row = ['action' => $action, 'user' => $user, 'member' => $member];

            if ($user->role === UserRole::Staff && $user->member_id === null) {
                $done[] = $row;   // already back to staff

                continue;
            }

            $lastRoleEvent = UserAccountEvent::where('user_id', $userId)->where('type', UserAccountEvent::ROLE_CHANGED)->latest('id')->first();
            $tag = $action === 'link' ? self::TAG_LINK : self::TAG_RETIRE;

            $problem = match (true) {
                $action === 'link' && $member === null => "Member {$membershipNo} does not exist.",
                $action === 'link' && ($user->role !== UserRole::Member || (int) $user->member_id !== (int) $member->id) => "User #{$userId} is no longer the member account of {$membershipNo} (role {$user->role->value}). Manual intervention required.",
                $action === 'retire' && $user->role !== UserRole::LegacyStaff => "User #{$userId} is {$user->role->value}, not legacy_staff. Manual intervention required.",
                $lastRoleEvent === null || ! str_starts_with((string) $lastRoleEvent->note, $tag) => "User #{$userId}'s latest role change was not made by this migration, so it will not be undone automatically. Manual intervention required.",
                default => null,
            };

            $problem ? $errors[] = $problem : $changes[] = $row;
        }

        return ['changes' => $changes, 'done' => $done, 'errors' => $errors];
    }

    public function revert(array $links, array $retire): array
    {
        return DB::transaction(function () use ($links, $retire) {
            $plan = $this->revertPlan($links, $retire, lock: true);
            if ($plan['errors']) {
                throw new RuntimeException(implode(PHP_EOL, $plan['errors']));
            }

            foreach ($plan['changes'] as $change) {
                $user = $change['user'];
                $from = $user->role;
                // Restore only what the migration changed: the role (and, for a link, the member link).
                $user->forceFill(['role' => UserRole::Staff, 'member_id' => null])->save();
                $this->audit($user, $from, UserRole::Staff, self::TAG_REVERT." of the {$change['action']} for user #{$user->id} via memberflow:retire-staff --revert.");
            }

            return $plan['changes'];
        });
    }

    private function user(int $id, bool $lock): ?User
    {
        return User::whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    private function hasOpenInvitation(Member $member, User $user): bool
    {
        return UserInvitation::where('status', InvitationStatus::Pending->value)
            ->where('expires_at', '>=', now())
            ->where(fn ($q) => $q->where('member_id', $member->id)->orWhere('email', strtolower(trim($user->email))))
            ->exists();
    }

    private function audit(User $user, UserRole $from, UserRole $to, string $note): void
    {
        UserAccountEvent::create([
            'user_id' => $user->id, 'actor_id' => null, 'type' => UserAccountEvent::ROLE_CHANGED,
            'old_value' => $from->value, 'new_value' => $to->value, 'note' => $note,
        ]);
    }
}
