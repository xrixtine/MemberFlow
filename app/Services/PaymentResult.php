<?php

namespace App\Services;

use App\Models\Payment;

/**
 * Outcome of PaymentService::record(). $duplicate is true when the same
 * idempotency key was submitted again and the original payment is returned
 * instead of creating a second one.
 */
final class PaymentResult
{
    public function __construct(
        public readonly Payment $payment,
        public readonly bool $duplicate = false,
    ) {}
}
