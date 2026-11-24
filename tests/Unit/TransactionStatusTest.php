<?php

namespace Tests\Unit;

use App\Enums\TransactionStatus as S;
use PHPUnit\Framework\TestCase;

class TransactionStatusTest extends TestCase
{
    public function test_allowed_transitions(): void
    {
        $this->assertTrue(S::Pending->canTransitionTo(S::Paid));
        $this->assertTrue(S::Pending->canTransitionTo(S::Cancelled));
        $this->assertTrue(S::Paid->canTransitionTo(S::Approved));
        $this->assertTrue(S::Paid->canTransitionTo(S::Pending)); // payment voided before approval
        $this->assertTrue(S::Approved->canTransitionTo(S::Expired));
    }

    public function test_forbidden_transitions(): void
    {
        // Only paid transactions may be approved.
        $this->assertFalse(S::Pending->canTransitionTo(S::Approved));
        // No double approval, no reviving final states.
        $this->assertFalse(S::Approved->canTransitionTo(S::Approved));
        $this->assertFalse(S::Approved->canTransitionTo(S::Cancelled));
        $this->assertFalse(S::Paid->canTransitionTo(S::Cancelled));
        $this->assertSame([], S::Cancelled->allowedTransitions());
        $this->assertSame([], S::Expired->allowedTransitions());
    }

    public function test_open_and_payable_states(): void
    {
        $this->assertTrue(S::Pending->isOpen());
        $this->assertTrue(S::Paid->isOpen());
        $this->assertFalse(S::Approved->isOpen());

        $this->assertTrue(S::Pending->acceptsPayments());
        foreach ([S::Paid, S::Approved, S::Expired, S::Cancelled] as $status) {
            $this->assertFalse($status->acceptsPayments(), $status->value);
        }
    }
}
