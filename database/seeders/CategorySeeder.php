<?php

namespace Database\Seeders;

use App\Models\MembershipCategory;
use Illuminate\Database\Seeder;

/**
 * Default membership categories from the project instructions.
 * Idempotent: existing categories (and admin edits to them) are left untouched.
 */
class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Student' => 'Currently enrolled students with a valid school ID.',
            'Regular' => 'Standard individual membership.',
            'Senior Citizen' => 'Members aged 60 and above.',
            'Corporate' => 'Memberships held on behalf of a company or organisation.',
        ];

        foreach ($categories as $name => $description) {
            MembershipCategory::firstOrCreate(['name' => $name], [
                'description' => $description,
                'is_active' => true,
            ]);
        }
    }
}
