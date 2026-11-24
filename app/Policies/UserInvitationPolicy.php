<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserInvitation;

/** Only active administrators issue, view, resend and revoke invitations. */
class UserInvitationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, UserInvitation $invitation): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function resend(User $user, UserInvitation $invitation): bool
    {
        return $this->isAdmin($user);
    }

    public function revoke(User $user, UserInvitation $invitation): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return $user->isAdmin() && $user->is_active;
    }
}
