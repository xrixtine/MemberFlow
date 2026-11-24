<?php

namespace Tests\Feature\Accounts;

use App\Enums\InvitationStatus;
use App\Enums\UserRole;
use App\Events\StaffInvitationIssued;
use App\Exceptions\AccountException;
use App\Models\Member;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Models\UserInvitation;
use App\Services\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Account invitations (member-account activation since R6; staff invitations retired).
 * Token security: random 64-char tokens, SHA-256 hash only, single use, 72-hour expiry,
 * rotated on resend, rate-limited pages.
 */
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private InvitationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(9, 0));
        $this->admin = User::factory()->admin()->create(['name' => 'Admin Alvarez']);
        $this->service = app(InvitationService::class);
    }

    private function member(string $email = 'new.member@example.test', string $first = 'New', string $last = 'Member'): Member
    {
        return Member::factory()->create(['email' => $email, 'first_name' => $first, 'middle_name' => null, 'last_name' => $last]);
    }

    /** @return array{0: UserInvitation, 1: string} */
    private function invite(string $email = 'new.member@example.test'): array
    {
        return $this->service->issueForMember($this->member($email), $this->admin);
    }

    private function acceptPayload(array $overrides = []): array
    {
        return array_merge(['password' => 'Activate123', 'password_confirmation' => 'Activate123'], $overrides);
    }

    // ---- Issuing ---------------------------------------------------------------------

    public function test_admin_issues_a_member_invitation_without_creating_a_user(): void
    {
        Event::fake([StaffInvitationIssued::class]);
        $member = $this->member('maria.clara@example.test', 'Maria', 'Clara');

        $this->actingAs($this->admin)->post(route('admin.members.account-invitation', $member), ['role' => 'admin'])
            ->assertSessionHas('success');

        $invitation = UserInvitation::sole();
        $this->assertSame('maria.clara@example.test', $invitation->email);
        $this->assertSame(UserRole::Member, $invitation->role);          // a "role" in the request is ignored
        $this->assertSame($member->id, $invitation->member_id);
        $this->assertSame(InvitationStatus::Pending, $invitation->status);
        $this->assertSame('2026-11-15 09:00:00', $invitation->expires_at->toDateTimeString()); // 72 hours
        $this->assertTrue($invitation->inviter->is($this->admin));
        $this->assertSame(1, User::count()); // only the admin: no account before acceptance
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $invitation->token_hash);

        // The raw token exists only in the (after-commit) event, and only its hash is stored.
        $token = null;
        Event::assertDispatched(StaffInvitationIssued::class, function ($e) use ($invitation, &$token) {
            $token = $e->rawToken;

            return $e->invitation->is($invitation) && ! $e->isResend;
        });
        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertDatabaseMissing('user_invitations', ['token_hash' => $token]);

        // The admin pages never show the token.
        $this->actingAs($this->admin)->get(route('admin.invitations.show', $invitation))->assertOk()->assertDontSee($token)->assertDontSee($invitation->token_hash);
        $this->actingAs($this->admin)->get(route('admin.invitations.index'))->assertOk()->assertDontSee($token)->assertSee('maria.clara@example.test')->assertSee('Member account');
    }

    public function test_staff_invitations_can_no_longer_be_created(): void
    {
        $this->actingAs($this->admin)->get('/admin/invitations/create')->assertNotFound();
        $this->actingAs($this->admin)->post('/admin/invitations', ['name' => 'Old Way', 'email' => 'old.way@example.test'])->assertStatus(405);
        $this->assertFalse(method_exists($this->service, 'issue'));
        $this->assertSame(0, UserInvitation::count());
    }

    public function test_duplicate_pending_invitation_and_existing_users_are_rejected(): void
    {
        [$first] = $this->invite('dup@example.test');
        $taken = $this->member('taken@example.test', 'Taken', 'Address');
        User::factory()->admin()->create(['email' => 'taken@example.test']);

        $this->actingAs($this->admin)->post(route('admin.members.account-invitation', $first->member))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'already pending'));
        $this->actingAs($this->admin)->post(route('admin.members.account-invitation', $taken))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Another login account already uses this email'));

        $this->assertSame(1, UserInvitation::count());
    }

    public function test_a_new_invitation_may_replace_an_expired_one(): void
    {
        [$old] = $this->invite('late@example.test');
        $this->travel(73)->hours();

        [$new] = $this->service->issueForMember($old->member, $this->admin);

        $this->assertSame(InvitationStatus::Expired, $old->fresh()->status);
        $this->assertSame(InvitationStatus::Pending, $new->status);
    }

    // ---- Accepting ---------------------------------------------------------------------

    public function test_invitee_activates_a_member_account_and_lands_on_the_member_dashboard(): void
    {
        [$invitation, $token] = $this->invite();

        $this->get(route('invitation.show', $token))
            ->assertOk()
            ->assertSee('Activate your account')
            ->assertSee('new.member@example.test')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->post(route('invitation.accept', $token), $this->acceptPayload([
            'email' => 'attacker@example.test', 'role' => 'admin', 'member_id' => '999',
        ]))->assertRedirect(route('dashboard'))->assertSessionHas('success');

        $user = User::where('email', 'new.member@example.test')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('New Member', $user->name);
        $this->assertTrue($user->isMember());
        $this->assertSame($invitation->member_id, $user->member_id);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->inviter->is($this->admin));
        $this->assertNotSame('Activate123', $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('Activate123', $user->password));
        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.test']);

        $invitation->refresh();
        $this->assertSame(InvitationStatus::Accepted, $invitation->status);
        $this->assertTrue($invitation->acceptedUser->is($user));
        $this->assertSame(UserAccountEvent::ACTIVATED, UserAccountEvent::where('user_id', $user->id)->value('type'));

        $this->get(route('dashboard'))->assertOk()->assertSee('Welcome, New.');
    }

    public function test_invited_person_cannot_log_in_before_activation_but_can_after(): void
    {
        [, $token] = $this->invite('waiting@example.test');

        $this->post(route('login.store'), ['email' => 'waiting@example.test', 'password' => 'Activate123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('invitation.accept', $token), $this->acceptPayload());
        $this->post(route('logout'));

        $this->post(route('login.store'), ['email' => 'waiting@example.test', 'password' => 'Activate123'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_tokens_are_single_use(): void
    {
        [, $token] = $this->invite();

        $this->post(route('invitation.accept', $token), $this->acceptPayload());
        $this->post(route('logout'));

        $this->get(route('invitation.show', $token))->assertStatus(410)->assertSee('already been used');
        $this->post(route('invitation.accept', $token), $this->acceptPayload(['password' => 'Another123', 'password_confirmation' => 'Another123']))
            ->assertStatus(410);

        $this->assertSame(1, User::where('email', 'new.member@example.test')->count());
        $this->assertTrue(Hash::check('Activate123', User::where('email', 'new.member@example.test')->value('password')));
    }

    public function test_concurrent_acceptance_with_a_stale_lookup_creates_only_one_account(): void
    {
        [, $token] = $this->invite();

        $this->service->accept($token, null, 'Activate123');

        try {
            $this->service->accept($token, null, 'Activate456');
            $this->fail('The second acceptance must be rejected.');
        } catch (AccountException $e) {
            $this->assertStringContainsString('already been used', $e->getMessage());
        }

        $this->assertSame(1, User::where('email', 'new.member@example.test')->count());
        $this->assertTrue(Hash::check('Activate123', User::where('email', 'new.member@example.test')->value('password')));
    }

    public function test_invalid_expired_and_revoked_links_are_rejected(): void
    {
        $this->get(route('invitation.show', str_repeat('a', 64)))->assertNotFound()->assertSee('This invitation link is invalid');
        $this->get('/invitations/short')->assertNotFound();

        [$revoked, $revokedToken] = $this->invite('revoked@example.test');
        $this->service->revoke($revoked, $this->admin);
        $this->get(route('invitation.show', $revokedToken))->assertStatus(410)->assertSee('has been revoked');

        [, $expiredToken] = $this->invite('expired@example.test');
        $this->travel(72 * 3600 + 1)->seconds(); // one second past the 72-hour expiry
        $this->get(route('invitation.show', $expiredToken))->assertStatus(410)->assertSee('has expired');
        $this->post(route('invitation.accept', $expiredToken), $this->acceptPayload())->assertStatus(410);

        $this->assertSame(1, User::count());
    }

    public function test_a_leftover_pending_staff_invitation_cannot_be_accepted_or_resent(): void
    {
        // Shape of a staff invitation issued before R6 (raw insert: the app can no longer create one).
        $token = str_repeat('S', 64);
        DB::table('user_invitations')->insert([
            'email' => 'old.staff@example.test', 'name' => 'Old Staff', 'role' => 'staff', 'token_hash' => hash('sha256', $token),
            'status' => 'pending', 'expires_at' => now()->addDay(), 'invited_by' => $this->admin->id, 'send_count' => 1,
            'last_sent_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $invitation = UserInvitation::sole();

        $this->get(route('invitation.show', $token))->assertStatus(410)->assertSee('Staff accounts were retired');
        $this->post(route('invitation.accept', $token), ['name' => 'Old Staff'] + $this->acceptPayload())->assertStatus(410);
        $this->actingAs($this->admin)->post(route('admin.invitations.resend', $invitation))->assertSessionHas('error', InvitationService::STAFF_RETIRED_MESSAGE);

        $this->assertSame(1, User::count());
        $this->assertSame(InvitationStatus::Pending, $invitation->fresh()->status);
        $this->actingAs($this->admin)->get(route('admin.invitations.index'))->assertSee('Staff (historical)');
    }

    public function test_password_rules_are_enforced_and_a_name_cannot_be_chosen(): void
    {
        [, $token] = $this->invite();

        $this->post(route('invitation.accept', $token), $this->acceptPayload(['password' => 'weak', 'password_confirmation' => 'weak']))
            ->assertSessionHasErrors('password');
        $this->post(route('invitation.accept', $token), $this->acceptPayload(['password_confirmation' => 'Different123']))
            ->assertSessionHasErrors('password');
        $this->post(route('invitation.accept', $token), $this->acceptPayload(['name' => 'Someone Else']))->assertSessionHasErrors('name');

        $this->assertSame(1, User::count());
        $this->assertSame(InvitationStatus::Pending, UserInvitation::sole()->status);
    }

    public function test_signed_in_user_opening_a_link_is_signed_out_first(): void
    {
        [, $token] = $this->invite();

        $this->actingAs($this->admin)->get(route('invitation.show', $token))->assertRedirect(route('invitation.show', $token));
        $this->assertGuest();
    }

    public function test_invitation_links_are_rate_limited(): void
    {
        foreach (range(1, 10) as $i) {
            $this->get(route('invitation.show', str_repeat('b', 64)))->assertNotFound();
        }

        $this->get(route('invitation.show', str_repeat('b', 64)))->assertStatus(429);
    }

    // ---- Resend / revoke / expire ----------------------------------------------------------

    public function test_resend_rotates_the_token_and_extends_expiry(): void
    {
        Event::fake([StaffInvitationIssued::class]);
        [$invitation, $oldToken] = $this->invite();
        $this->travel(2)->hours();

        $this->actingAs($this->admin)->post(route('admin.invitations.resend', $invitation))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'previous link no longer works'))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'No invitation email was queued'));  // the event is faked here; delivery is covered in tests/Feature/Email

        $invitation->refresh();
        $this->assertSame(2, $invitation->send_count);
        $this->assertSame('2026-11-15 11:00:00', $invitation->expires_at->toDateTimeString());
        $this->assertNotSame(hash('sha256', $oldToken), $invitation->token_hash);

        $newToken = null;
        Event::assertDispatched(StaffInvitationIssued::class, function ($e) use (&$newToken) {
            if ($e->isResend) {
                $newToken = $e->rawToken;
            }

            return $e->isResend;
        });

        auth()->logout(); // the invitee is a guest (a signed-in visitor is signed out first)
        $this->get(route('invitation.show', $oldToken))->assertNotFound();
        $this->get(route('invitation.show', $newToken))->assertOk();
    }

    public function test_resend_rules(): void
    {
        [$invitation] = $this->invite();

        // Cool-down of one minute between sends.
        $this->actingAs($this->admin)->post(route('admin.invitations.resend', $invitation))->assertSessionHas('error');
        $this->assertSame(1, $invitation->fresh()->send_count);

        // An expired invitation can be resent and becomes pending again.
        $this->travel(80)->hours();
        $this->service->expireDue();
        $this->assertSame(InvitationStatus::Expired, $invitation->fresh()->status);
        $this->actingAs($this->admin)->post(route('admin.invitations.resend', $invitation))->assertSessionHas('success');
        $this->assertSame(InvitationStatus::Pending, $invitation->fresh()->status);

        // Revoked invitations cannot be resent.
        [$revoked] = $this->invite('r@example.test');
        $this->service->revoke($revoked, $this->admin);
        $this->travel(2)->minutes();
        $this->actingAs($this->admin)->post(route('admin.invitations.resend', $revoked))->assertSessionHas('error');
        $this->assertSame(InvitationStatus::Revoked, $revoked->fresh()->status);
    }

    public function test_revoke_only_pending_invitations(): void
    {
        [$invitation, $token] = $this->invite();

        $this->actingAs($this->admin)->patch(route('admin.invitations.revoke', $invitation))->assertSessionHas('success');
        $invitation->refresh();
        $this->assertSame(InvitationStatus::Revoked, $invitation->status);
        $this->assertTrue($invitation->revoker->is($this->admin));

        $this->actingAs($this->admin)->patch(route('admin.invitations.revoke', $invitation))->assertSessionHas('error');

        auth()->logout();
        $this->post(route('invitation.accept', $token), $this->acceptPayload())->assertStatus(410);
        $this->assertSame(1, User::count());
    }

    public function test_expiry_command_and_effective_status_filter(): void
    {
        [$invitation] = $this->invite('soon@example.test');
        $this->invite('fresh@example.test');
        $this->travel(73)->hours();
        $this->invite('newest@example.test');

        // Shown as expired even before the daily command runs.
        $this->assertSame(InvitationStatus::Expired, $invitation->fresh()->effectiveStatus());
        $expired = $this->actingAs($this->admin)->get(route('admin.invitations.index', ['status' => 'expired']))->viewData('invitations');
        $this->assertCount(2, $expired);
        $this->assertCount(1, $this->actingAs($this->admin)->get(route('admin.invitations.index', ['status' => 'pending']))->viewData('invitations'));

        $this->artisan('memberflow:expire-invitations')->expectsOutputToContain('Expired 2 invitation(s).')->assertSuccessful();
        $this->assertSame(InvitationStatus::Expired, $invitation->fresh()->status);
    }

    public function test_development_link_command_rotates_the_token_and_prints_a_working_link(): void
    {
        [, $original] = $this->invite('cli@example.test');

        $this->artisan('memberflow:invitation-link', ['email' => 'cli@example.test'])
            ->expectsOutputToContain('Any previous link for this invitation no longer works.')
            ->expectsOutputToContain(url('/invitations/'))
            ->assertSuccessful();

        $this->get(route('invitation.show', $original))->assertNotFound();

        $invitation = UserInvitation::sole();
        $this->assertSame(2, $invitation->send_count);

        $this->artisan('memberflow:invitation-link', ['email' => 'nobody@example.test'])->assertFailed();
    }

    // ---- Authorization ---------------------------------------------------------------------

    public function test_only_administrators_manage_invitations(): void
    {
        [$invitation] = $this->invite();
        $member = User::factory()->member()->create();

        $requests = [
            ['get', route('admin.invitations.index')],
            ['get', route('admin.invitations.show', $invitation)],
            ['post', route('admin.invitations.resend', $invitation)],
            ['patch', route('admin.invitations.revoke', $invitation)],
            ['post', route('admin.members.account-invitation', $invitation->member)],
        ];

        foreach ($requests as [$method, $url]) {
            $this->actingAs($member)->{$method}($url)->assertForbidden();
        }
        auth()->logout();
        foreach ($requests as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('login'));
        }

        $this->assertSame(1, UserInvitation::count());
        $this->assertSame(InvitationStatus::Pending, $invitation->fresh()->status);
    }
}
