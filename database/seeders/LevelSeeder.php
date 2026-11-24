<?php

namespace Database\Seeders;

use App\Models\MembershipLevel;
use Illuminate\Database\Seeder;

/**
 * Default membership levels from the project instructions. Fees are sample
 * values that administrators can change on the Levels page.
 * Idempotent: existing levels (and admin edits to them) are left untouched.
 */
class LevelSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            ['name' => 'Basic', 'fee' => '500.00', 'validity_months' => 12, 'sort_order' => 1,
                'benefits' => "Membership card\nMonthly newsletter"],
            ['name' => 'Silver', 'fee' => '1000.00', 'validity_months' => 12, 'sort_order' => 2,
                'benefits' => "All Basic benefits\n10% discount on events\nAccess to member workshops"],
            ['name' => 'Gold', 'fee' => '2000.00', 'validity_months' => 12, 'sort_order' => 3,
                'benefits' => "All Silver benefits\n20% discount on events\nPriority registration"],
            ['name' => 'Platinum', 'fee' => '3500.00', 'validity_months' => 12, 'sort_order' => 4,
                'benefits' => "All Gold benefits\nFree annual event pass\nDedicated member support"],
        ];

        foreach ($levels as $level) {
            MembershipLevel::firstOrCreate(['name' => $level['name']], $level + ['is_active' => true]);
        }
    }
}
