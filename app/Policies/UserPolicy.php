<?php

namespace App\Policies;

use App\Models\User;

/**
 * User management. There is deliberately no "create" or "delete" ability:
 * accounts come only from invitations (or the initial-admin seeder) and are
 * deactivated, never deleted.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isAdmin($actor);
    }

    public function view(User $actor, User $user): bool
    {
        return $this->isAdmin($actor);
    }

    public function update(User $actor, User $user): bool
    {
        return $this->isAdmin($actor);
    }

    /** Activate/deactivate. Self-deactivation is refused here and in the service. */
    public function changeStatus(User $actor, User $user): bool
    {
        return $this->isAdmin($actor) && ! $actor->is($user);
    }

    private function isAdmin(User $user): bool
    {
        return $user->isAdmin() && $user->is_active;
    }
}
