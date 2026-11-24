<?php

namespace Database\Factories;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Member;
use App\Models\MembershipLevel;
use App\Models\MembershipTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MembershipTransaction>
 */
class MembershipTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'membership_level_id' => MembershipLevel::factory(),
            'type' => TransactionType::Application,
            'amount' => '1000.00',
            'validity_months' => 12,
            'amount_paid' => '0.00',
            'status' => TransactionStatus::Pending,
        ];
    }
}
