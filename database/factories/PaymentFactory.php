<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Raw payment rows for relationship tests. Real payments must go through
 * PaymentService (locking, balance checks, amount_paid update).
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'membership_transaction_id' => MembershipTransaction::factory(),
            'amount' => '500.00',
            'method' => PaymentMethod::Cash,
            'paid_at' => now(),
            'received_by' => User::factory()->admin(),
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
