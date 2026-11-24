<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Accounts may only be created by the initial-admin seeder or (Phase 7a) by
 * accepting an invitation. There must be no self-registration path.
 */
class AccountCreationPathsTest extends TestCase
{
    use RefreshDatabase;

    public function test_there_is_no_registration_route(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Intruder', 'email' => 'intruder@example.test',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.test']);
    }

    public function test_there_is_no_direct_user_creation_route(): void
    {
        $this->assertFalse(Route::has('admin.users.create'));
        $this->assertFalse(Route::has('admin.users.store'));
    }
}
