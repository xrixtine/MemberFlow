<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Test/demo users only. Emails use the reserved fictional domain example.test.
 *
 * The default is a MEMBER account linked to its own (active) member record, the
 * normal non-administrator account since R6. admin(), staff() and legacyStaff()
 * carry no member link (a database CHECK requires that).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->userName().'@example.test',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('Password123'),
            'role' => UserRole::Member,
            'member_id' => Member::factory()->active(),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin, 'member_id' => null]);
    }

    /**
     * Historical staff account (the retired role): for migration and history tests only.
     * It cannot sign in.
     */
    public function staff(): static
    {
        return $this->state(fn () => ['role' => UserRole::Staff, 'member_id' => null]);
    }

    /**
     * Member account linked one-to-one to a member record. The account uses the
     * member's email and name, as member-account activation does.
     */
    public function member(?Member $member = null): static
    {
        return $this->state(function () use ($member) {
            $member ??= Member::factory()->active()->create();

            return [
                'role' => UserRole::Member,
                'member_id' => $member->id,
                'email' => $member->email,
                'name' => $member->fullName(),
            ];
        });
    }

    /** Retired staff account: historical row only, cannot sign in. */
    public function legacyStaff(): static
    {
        return $this->state(fn () => ['role' => UserRole::LegacyStaff, 'member_id' => null, 'is_active' => false]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
