<?php

namespace Database\Factories;

use App\Enums\MemberSource;
use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\MembershipLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fictional members for tests and demos (example.test emails).
 *
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->lastName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->userName().'@example.test',
            'phone' => '09'.fake()->numerify('#########'),
            'address' => fake()->streetAddress().', Manila',
            'membership_category_id' => MembershipCategory::factory(),
            'membership_level_id' => MembershipLevel::factory(),
            'source' => MemberSource::WalkIn,
            'status' => MemberStatus::Pending,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => MemberStatus::Active,
            'membership_start' => today()->subMonths(2),
            'membership_end' => today()->addMonths(10)->subDay(),
        ]);
    }

    public function online(): static
    {
        return $this->state(fn () => ['source' => MemberSource::Online]);
    }

    public function verified(): static
    {
        return $this->state(fn () => ['email_verified_at' => now()]);
    }
}
