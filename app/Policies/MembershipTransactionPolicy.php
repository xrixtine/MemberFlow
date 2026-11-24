<?php

namespace App\Policies;

use App\Models\MembershipTransaction;
use App\Models\User;

/**
 * Who may do what with membership transactions. Only active administrators
 * manage transactions. Business-state rules (e.g. "only paid transactions
 * can be approved") are enforced separately by MembershipService.
 */
class MembershipTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, MembershipTransaction $transaction): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function approve(User $user, MembershipTransaction $transaction): bool
    {
        return $this->isAdmin($user);
    }

    public function cancel(User $user, MembershipTransaction $transaction): bool
    {
        return $this->isAdmin($user);
    }

    public function runExpiry(User $user): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return $user->isAdmin() && $user->is_active;
    }
}
