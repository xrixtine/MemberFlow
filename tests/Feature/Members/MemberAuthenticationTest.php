<?php

namespace Tests\Feature\Members;

use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase R2 — member sign-in, session checks and route protection.
 */
class MemberAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC = 'These credentials do not match an active MemberFlow account.';

    private function login(User $user, array $extra = [])
    {
        return $this->post('/login', ['email' => $user->email, 'password' => 'Password123'] + $extra);
    }

    // ---- Sign-in --------------------------------------------------------------

    public function test_member_signs_in_and_lands_on_their_own_dashboard(): void
    {
        $member = Member::factory()->active()->create(['first_name' => 'Marisol']);
        $user = User::factory()->member($member)->create();

        $this->login($user)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Welcome, Marisol.')
            ->assertSee($member->membership_no ?? 'membership number pending')
            ->assertSee('My membership');
        $this->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_sign_in_regenerates_the_session_id(): void
    {
        $user = User::factory()->member()->create();
        $this->get('/login');
        $before = session()->getId();

        $this->login($user);

        $this->assertNotSame($before, session()->getId());
    }

    public function test_pending_and_expired_memberships_can_still_sign_in(): void
    {
        foreach ([MemberStatus::Pending, MemberStatus::Expired] as $status) {
            $user = User::factory()->member(Member::factory()->create(['status' => $status]))->create();
            $this->login($user)->assertRedirect(route('dashboard'));
            $this->assertAuthenticatedAs($user);
            $this->post('/logout');
        }
    }

    public function test_deactivated_member_account_is_refused_with_the_generic_message(): void
    {
        $user = User::factory()->member()->inactive()->create();

        $this->login($user)->assertSessionHasErrors(['email' => self::GENERIC]);
        $this->assertGuest();
    }

    public function test_member_account_is_refused_when_the_member_record_is_deactivated(): void
    {
        $user = User::factory()->member(Member::factory()->create(['status' => MemberStatus::Inactive]))->create();

        $this->login($user)->assertSessionHasErrors(['email' => self::GENERIC]);
        $this->assertGuest();
    }

    public function test_member_account_is_refused_when_the_member_record_was_deleted(): void
    {
        $member = Member::factory()->create();
        $user = User::factory()->member($member)->create();
        $member->delete(); // soft delete outside the service guard (e.g. legacy data)

        $this->assertFalse($user->fresh()->hasValidMemberLink());
        $this->login($user)->assertSessionHasErrors(['email' => self::GENERIC]);
        $this->assertGuest();
    }

    public function test_wrong_password_for_a_member_is_rejected(): void
    {
        $user = User::factory()->member()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'WrongPassword1'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_throttling_applies_to_member_accounts(): void
    {
        $user = User::factory()->member()->create();
        foreach (range(1, 5) as $i) {
            $this->post('/login', ['email' => $user->email, 'password' => 'Wrong'.$i.'Aa']);
        }

        $this->login($user)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_extra_role_fields_on_the_login_form_are_ignored(): void
    {
        $user = User::factory()->member()->create();

        $this->login($user, ['role' => 'admin', 'is_active' => '1', 'member_id' => '999'])->assertRedirect(route('dashboard'));
        $this->assertSame(UserRole::Member, $user->fresh()->role);
        $this->get('/admin/dashboard')->assertForbidden();
    }

    // ---- Session invalidation -----------------------------------------------------

    public function test_member_signed_out_when_account_is_deactivated_during_a_session(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get(route('my.membership'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_member_signed_out_when_member_record_is_deactivated_during_a_session(): void
    {
        $member = Member::factory()->active()->create();
        $user = User::factory()->member($member)->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $member->forceFill(['status' => MemberStatus::Inactive])->save();

        // A real request re-reads the user (and their member record) from the database;
        // actingAs() would otherwise keep the stale in-memory model for the whole test.
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_legacy_staff_with_an_active_row_can_never_sign_in_or_keep_a_session(): void
    {
        $retired = User::factory()->legacyStaff()->create(['is_active' => true]);

        $this->login($retired)->assertSessionHasErrors(['email' => self::GENERIC]);
        $this->actingAs($retired)->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ---- Route protection ---------------------------------------------------------

    public function test_member_is_refused_every_administrator_route(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        foreach (['/admin/dashboard', '/admin/members', '/admin/members/'.$user->member_id, '/admin/members/create', '/admin/transactions',
            '/admin/payments', '/admin/payments/create', '/admin/tasks', '/admin/tasks/create', '/admin/users', '/admin/users/'.$user->id.'/edit',
            '/admin/invitations', '/admin/reports', '/admin/email-deliveries', '/admin/categories', '/admin/levels'] as $uri) {
            $this->get($uri)->assertForbidden();
        }
        foreach ([['post', '/admin/tasks'], ['put', '/admin/users/'.$user->id], ['patch', '/admin/users/'.$user->id.'/deactivate'],
            ['patch', '/admin/members/'.$user->member_id.'/deactivate'], ['post', '/admin/payments'], ['post', '/admin/members/'.$user->member_id.'/account-invitation']] as [$method, $uri]) {
            $this->{$method}($uri, ['title' => 'x', 'assigned_to' => $user->id, 'confirm_email' => $user->email])->assertForbidden();
        }
        $this->assertSame(UserRole::Member, $user->fresh()->role);
    }

    public function test_member_portal_routes_are_for_members_only(): void
    {
        $routes = ['my.membership', 'my.payments.index'];
        foreach ($routes as $route) {
            $this->get(route($route))->assertRedirect(route('login'));   // guest
        }
        $this->actingAs(User::factory()->admin()->create());
        foreach ($routes as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->get(route('dashboard'))->assertForbidden();

        // Old staff rows (historical values) cannot even keep a session.
        $this->actingAs(User::factory()->staff()->create());
        foreach ($routes as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        $this->assertGuest();
    }

    public function test_every_member_route_responds_for_a_member(): void
    {
        $this->actingAs(User::factory()->member()->create());

        foreach (['/dashboard', '/my/membership', '/my/payments', '/my/tasks', '/my/tasks?tab=previous', '/my/history', '/profile'] as $uri) {
            $this->get($uri)->assertOk();
        }
    }

    // ---- Retired staff role and administrators -------------------------------------------

    public function test_the_retired_staff_roles_cannot_sign_in(): void
    {
        foreach ([User::factory()->staff()->create(), User::factory()->legacyStaff()->create(['is_active' => true])] as $old) {
            $this->login($old)->assertSessionHasErrors(['email' => self::GENERIC]);
            $this->assertGuest();
        }
    }

    public function test_administrator_sign_in_is_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $this->login($admin)->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.dashboard'))->assertOk();
    }
}
