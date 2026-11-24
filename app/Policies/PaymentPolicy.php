<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/**
 * Only active administrators record, list and void payments. A member account
 * may view (and print the receipt of) a payment only when it belongs to one of
 * its OWN member record's transactions. Financial state rules (overpayment,
 * voiding after approval, ...) live in PaymentService.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->isAdmin($user) || $this->ownsAsMember($user, $payment);
    }

    private function ownsAsMember(User $user, Payment $payment): bool
    {
        if (! $user->isMember() || ! $user->canSignIn()) {
            return false;
        }

        $memberId = $payment->transaction()->value('member_id');

        return $memberId !== null && (int) $memberId === (int) $user->member_id;
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function void(User $user, Payment $payment): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return $user->isAdmin() && $user->is_active;
    }
}
