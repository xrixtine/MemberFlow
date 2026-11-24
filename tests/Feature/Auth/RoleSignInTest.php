<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Revision R1 — which roles may authenticate during the transition.
 *
 *  - legacy_staff: never (historical value only)
 *  - member: yes, with a valid member link (see MemberAuthenticationTest)
 *  - admin and the existing staff accounts: unchanged
 */
class RoleSignInTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC = 'These credentials do not match an active MemberFlow account.';

    public function test_exactly_two_roles_can_sign_in(): void
    {
        $this->assertSame(['admin', 'member'], UserRole::signInValues());
        $this->assertFalse(UserRole::Staff->canSignIn());
        $this->assertFalse(UserRole::LegacyStaff->canSignIn());
        $this->assertFalse(UserRole::Staff->worksOnTasks());
        $this->assertTrue(UserRole::Member->canSignIn());
        $this->assertTrue(UserRole::Member->requiresMemberLink());
        $this->assertFalse(UserRole::Staff->requiresMemberLink());
    }

    public function test_retired_staff_cannot_sign_in_even_if_the_row_is_active(): void
    {
        $retired = User::factory()->legacyStaff()->create(['is_active' => true]);

        $this->post('/login', ['email' => $retired->email, 'password' => 'Password123'])
            ->assertSessionHasErrors(['email' => self::GENERIC]);
        $this->assertGuest();
    }

    public function test_member_accounts_sign_in_to_their_dashboard(): void
    {
        $member = User::factory()->member()->create();

        $this->post('/login', ['email' => $member->email, 'password' => 'Password123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($member);
    }

    public function test_administrators_sign_in_and_the_old_staff_role_cannot(): void
    {
        $admin = User::factory()->admin()->create();
        $this->post('/login', ['email' => $admin->email, 'password' => 'Password123'])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
        $this->post('/logout');

        $staff = User::factory()->staff()->create();   // an un-migrated staff row (historical value)
        $this->post('/login', ['email' => $staff->email, 'password' => 'Password123'])->assertSessionHasErrors(['email' => self::GENERIC]);
        $this->assertGuest();
    }

    public function test_existing_staff_and_retired_sessions_are_signed_out_on_the_next_request(): void
    {
        foreach ([UserRole::Staff, UserRole::LegacyStaff] as $role) {
            $user = User::factory()->staff()->create(['role' => $role]);
            $this->actingAs($user)->get('/profile')->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }
}
