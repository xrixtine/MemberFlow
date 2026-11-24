<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Exceptions\AccountException;
use App\Models\Task;
use App\Models\User;
use App\Models\UserAccountEvent;
use Illuminate\Support\Facades\DB;

/**
 * Administrator user management. Accounts are never deleted: deactivation keeps
 * every relationship (tasks, activity, payments, approvals) intact. Each change
 * writes an audit row to user_account_events.
 */
class UserAccountService
{
    public function updateDetails(User $user, array $data, User $admin): User
    {
        return DB::transaction(function () use ($user, $data, $admin) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            // A member account's name and login email follow its membership record.
            if ($locked->isMember()) {
                throw new AccountException('Member account details come from the membership record. Edit the member instead.', 'user');
            }

            $before = $locked->only(['name', 'email']);

            $locked->fill(array_intersect_key($data, array_flip(['name', 'email'])));
            $changed = array_keys($locked->getDirty());

            if ($changed) {
                $locked->save();
                UserAccountEvent::create([
                    'user_id' => $locked->id,
                    'actor_id' => $admin->id,
                    'type' => UserAccountEvent::DETAILS_UPDATED,
                    'note' => collect($changed)->map(fn ($f) => "{$f}: {$before[$f]} → {$locked->{$f}}")->implode('; '),
                ]);
            }

            return $locked;
        });
    }

    /** @return int number of active tasks still assigned to the user (for the admin's attention) */
    public function deactivate(User $user, User $admin): int
    {
        return DB::transaction(function () use ($user, $admin) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->is($admin)) {
                throw new AccountException('You cannot deactivate your own account.', 'user');
            }
            if (! $locked->is_active) {
                throw new AccountException('This account is already inactive.', 'user');
            }
            if ($locked->isAdmin()) {
                // Lock all active admins so two admins cannot deactivate each other at once.
                $activeAdmins = User::admins()->active()->lockForUpdate()->count();
                if ($activeAdmins <= 1) {
                    throw new AccountException('The last active administrator cannot be deactivated.', 'user');
                }
            }

            $locked->forceFill(['is_active' => false])->save();
            $this->audit($locked, $admin, UserAccountEvent::DEACTIVATED);

            return Task::active()->where('assigned_to', $locked->id)
                ->where('status', '!=', TaskStatus::Completed->value)->count();
        });
    }

    public function reactivate(User $user, User $admin): User
    {
        return DB::transaction(function () use ($user, $admin) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_active) {
                throw new AccountException('This account is already active.', 'user');
            }

            $locked->forceFill(['is_active' => true])->save();
            $this->audit($locked, $admin, UserAccountEvent::REACTIVATED);

            return $locked;
        });
    }

    private function audit(User $user, User $actor, string $type, ?string $old = null, ?string $new = null): void
    {
        UserAccountEvent::create([
            'user_id' => $user->id, 'actor_id' => $actor->id, 'type' => $type, 'old_value' => $old, 'new_value' => $new,
        ]);
    }
}
