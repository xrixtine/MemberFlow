<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed reference data and the initial administrator.
     * Every seeder here is idempotent and safe to run more than once.
     */
    public function run(): void
    {
        $this->call([
            CategorySeeder::class,
            LevelSeeder::class,
            UserSeeder::class,
        ]);
    }
}
