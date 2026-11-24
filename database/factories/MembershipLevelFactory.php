<?php

namespace Database\Factories;

use App\Models\MembershipLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MembershipLevel>
 */
class MembershipLevelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Level '.fake()->unique()->numberBetween(1, 99999),
            'fee' => '1000.00',
            'validity_months' => 12,
            'benefits' => "Member newsletter\nEvent access",
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function fee(string $fee): static
    {
        return $this->state(fn () => ['fee' => $fee]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
