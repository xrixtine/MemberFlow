<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Single source of truth for membership transaction status transitions.
 *
 *   pending --(balance reaches 0)--> paid --(admin approves)--> approved --(period ends)--> expired
 *   pending --(admin cancels, no payments)--> cancelled
 *   paid    --(payment voided before approval)--> pending
 */
enum TransactionStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Paid = 'paid';
    case Approved = 'approved';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Paid => 'blue',
            self::Approved => 'green',
            self::Expired => 'gray',
            self::Cancelled => 'red',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Paid, self::Cancelled],
            self::Paid => [self::Approved, self::Pending],
            self::Approved => [self::Expired],
            self::Expired, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Open transactions block a new application/renewal for the same member. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Paid], true);
    }

    public function acceptsPayments(): bool
    {
        return $this === self::Pending;
    }
}
