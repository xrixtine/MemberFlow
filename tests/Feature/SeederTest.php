<?php

namespace Tests\Feature;

use App\Models\MembershipCategory;
use App\Models\MembershipLevel;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\LevelSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_categories_and_levels_are_seeded_idempotently(): void
    {
        $this->seed([CategorySeeder::class, LevelSeeder::class]);

        // An administrator later changes a fee; re-seeding must not overwrite it.
        MembershipLevel::where('name', 'Gold')->update(['fee' => '2500.00']);
        $this->seed([CategorySeeder::class, LevelSeeder::class]);

        $this->assertEqualsCanonicalizing(
            ['Student', 'Regular', 'Senior Citizen', 'Corporate'],
            MembershipCategory::pluck('name')->all(),
        );
        $this->assertSame(['Basic', 'Silver', 'Gold', 'Platinum'], MembershipLevel::ordered()->pluck('name')->all());
        $this->assertSame('2500.00', MembershipLevel::where('name', 'Gold')->value('fee'));
    }

    public function test_initial_admin_is_created_from_configuration(): void
    {
        config(['memberflow.admin' => [
            'name' => 'Test Admin', 'email' => 'first.admin@example.test', 'password' => 'Str0ngPassword',
        ]]);

        $this->artisan('db:seed', ['--class' => UserSeeder::class, '--force' => true])
            ->expectsOutputToContain('Initial administrator created')
            ->doesntExpectOutputToContain('Str0ngPassword')
            ->assertSuccessful();

        $admin = User::where('email', 'first.admin@example.test')->firstOrFail();
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check('Str0ngPassword', $admin->password));
    }

    public function test_seeder_refuses_when_an_admin_already_exists(): void
    {
        User::factory()->admin()->create();
        config(['memberflow.admin' => [
            'name' => 'Second', 'email' => 'second.admin@example.test', 'password' => 'Str0ngPassword',
        ]]);

        $seeder = new UserSeeder;
        $seeder->run();

        $this->assertSame(UserSeeder::RESULT_ADMIN_EXISTS, $seeder->result);
        $this->assertDatabaseMissing('users', ['email' => 'second.admin@example.test']);
        $this->assertSame(1, User::admins()->count());
    }

    public function test_seeder_does_nothing_when_not_configured(): void
    {
        config(['memberflow.admin' => ['name' => null, 'email' => null, 'password' => null]]);

        $seeder = new UserSeeder;
        $seeder->run();

        $this->assertSame(UserSeeder::RESULT_NOT_CONFIGURED, $seeder->result);
        $this->assertSame(0, User::count());
    }

    public function test_seeder_rejects_a_weak_password_without_revealing_it(): void
    {
        config(['memberflow.admin' => ['name' => 'Weak', 'email' => 'weak@example.test', 'password' => 'secretpw']]);

        $this->artisan('db:seed', ['--class' => UserSeeder::class, '--force' => true])
            ->expectsOutputToContain('Initial admin not created')
            ->doesntExpectOutputToContain('secretpw')
            ->assertSuccessful();

        $this->assertSame(0, User::count());
    }
}
