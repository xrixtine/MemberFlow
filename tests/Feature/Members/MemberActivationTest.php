<?php

namespace Tests\Feature\Members;

use App\Enums\EmailDeliveryStatus;
use App\Enums\InvitationStatus;
use App\Enums\MemberSource;
use App\Enums\MemberStatus;
use App\Enums\TransactionStatus;
use App\Enums\UserRole;
use App\Models\EmailDelivery;
use App\Models\Member;
use App\Models\MembershipTransaction;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Models\UserInvitation;
use App\Services\ApplicantVerificationService;
use App\Services\InvitationService;
use App\Services\MemberAccountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Tests\Feature\Email\EmailTestCase;

/**
 * Phase R3 — member account activation (online and walk-in) and invitation security.
 * Emails go to the in-memory "array" mailer; nothing leaves the machine.
 */
class MemberActivationTest extends EmailTestCase
{
    private const PASSWORD = 'MemberPass123';

    private function lastMail(): ?Email
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->last()?->getOriginalMessage();
    }

    private function tokenFromLastMail(): string
    {
        preg_match('#/invitations/([A-Za-z0-9]{64})#', (string) $this->lastMail()?->getHtmlBody(), $m);
        $this->assertNotEmpty($m, 'No activation link in the last email');

        return $m[1];
    }

    private function walkIn(array $attributes = []): Member
    {
        return Member::factory()->for($this->category, 'category')->for($this->gold, 'level')
            ->create(['email' => self::email('walk.in.'.Str::lower(Str::random(5))), 'source' => MemberSource::WalkIn] + $attributes);
    }

    private function activate(string $token, array $extra = [])
    {
        return $this->post(route('invitation.accept', $token), ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD] + $extra);
    }

    // ---- Online applicant: apply → verify → automatic invitation → activate ----------------

    public function test_online_applicant_is_invited_after_verifying_and_activates_a_linked_member_account(): void
    {
        $this->post(route('apply.store'), $this->applicationPayload())->assertRedirect(route('apply.submitted'));
        $member = Member::sole();
        $txn = MembershipTransaction::sole();
        $this->assertSame(0, UserInvitation::count(), 'No invitation before the email is verified');

        $this->get(app(ApplicantVerificationService::class)->verificationUrl($member))->assertOk()
            ->assertSee('activate your member account');

        $invitation = UserInvitation::sole();
        $this->assertSame(UserRole::Member, $invitation->role);
        $this->assertSame($member->id, $invitation->member_id);
        $this->assertNull($invitation->invited_by);                       // issued automatically by MemberFlow
        $this->assertSame($member->email, $invitation->email);
        $this->assertSame(self::email('lea.santos'), $this->lastMail()->getTo()[0]->getAddress());
        $this->assertStringContainsString('Activate your MemberFlow member account', $this->lastMail()->getSubject());
        $this->assertStringNotContainsString(self::PASSWORD, (string) $this->lastMail()->getHtmlBody());
        $this->assertSame(EmailDeliveryStatus::Logged, EmailDelivery::where('notification', 'MemberAccountInvitation')->sole()->status); // sent in-request (sync)

        $token = $this->tokenFromLastMail();
        $this->get(route('invitation.show', $token))->assertOk()->assertSee('member account')->assertDontSee('name="name"', false);
        $this->activate($token)->assertRedirect(route('dashboard'));

        $user = User::where('email', $member->email)->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(UserRole::Member, $user->role);
        $this->assertSame($member->id, $user->member_id);
        $this->assertSame($member->fullName(), $user->name);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNotSame(self::PASSWORD, $user->password);
        $this->assertSame(InvitationStatus::Accepted, $invitation->fresh()->status);
        $this->assertDatabaseHas('user_account_events', ['user_id' => $user->id, 'type' => UserAccountEvent::ACTIVATED, 'new_value' => 'member']);
        $this->get(route('dashboard'))->assertOk()->assertSee('Welcome, Lea.');

        // Activation is separate from approval and payment.
        $this->assertSame(MemberStatus::Pending, $member->fresh()->status);
        $this->assertSame(TransactionStatus::Pending, $txn->fresh()->status);
        $this->assertSame('0.00', $txn->fresh()->amount_paid);
    }

    public function test_verifying_twice_issues_only_one_invitation(): void
    {
        $this->post(route('apply.store'), $this->applicationPayload());
        $url = app(ApplicantVerificationService::class)->verificationUrl(Member::sole());

        $this->get($url)->assertOk();
        $this->get($url)->assertOk()->assertDontSee('activate your member account');

        $this->assertSame(1, UserInvitation::count());
    }

    public function test_walk_in_members_are_not_invited_automatically_when_they_verify(): void
    {
        $member = $this->walkIn();
        $this->get(app(ApplicantVerificationService::class)->verificationUrl($member))->assertOk();

        $this->assertTrue($member->fresh()->isEmailVerified());
        $this->assertSame(0, UserInvitation::count());
    }

    public function test_automatic_invitation_never_breaks_verification(): void
    {
        $this->post(route('apply.store'), $this->applicationPayload());
        $member = Member::sole();
        User::factory()->member()->create(['email' => $member->email]);      // address already used by another account

        $this->get(app(ApplicantVerificationService::class)->verificationUrl($member))->assertOk()->assertSee('Your email is verified');

        $this->assertTrue($member->fresh()->isEmailVerified());
        $this->assertSame(0, UserInvitation::count());
    }

    // ---- Walk-in: administrator invitation ----------------------------------------------------

    public function test_admin_invites_a_walk_in_member_who_activates_and_thereby_verifies_the_email(): void
    {
        $member = $this->walkIn(['first_name' => 'Walter', 'middle_name' => null]);
        $this->assertFalse($member->isEmailVerified());

        $this->actingAs($this->admin)->get(route('admin.members.show', $member))->assertOk()->assertSee('Send activation invitation');
        $this->post(route('admin.members.account-invitation', $member))->assertSessionHas('success');

        $invitation = UserInvitation::sole();
        $this->assertSame([UserRole::Member, $member->id, $this->admin->id], [$invitation->role, $invitation->member_id, $invitation->invited_by]);
        $this->get(route('admin.members.show', $member))->assertSee('Invitation pending')->assertSee('by Ada Admin');

        auth()->logout();
        $this->activate($this->tokenFromLastMail())->assertRedirect(route('dashboard'));

        $account = $member->fresh()->account;
        $this->assertNotNull($account);
        $this->assertTrue($member->fresh()->isEmailVerified());              // opening the emailed link proved ownership
        $this->assertSame($this->admin->id, $account->invited_by);
    }

    public function test_duplicate_invitations_are_refused_and_resend_rotates_the_link(): void
    {
        $member = $this->walkIn();
        $this->actingAs($this->admin)->post(route('admin.members.account-invitation', $member))->assertSessionHas('success');
        $oldToken = $this->tokenFromLastMail();

        $this->post(route('admin.members.account-invitation', $member))->assertSessionHas('error');
        $this->assertSame(1, UserInvitation::count());

        $this->travel(2)->minutes();
        $this->post(route('admin.invitations.resend', UserInvitation::sole()))->assertSessionHas('success');
        $newToken = $this->tokenFromLastMail();
        $this->assertNotSame($oldToken, $newToken);

        auth()->logout();
        $this->get(route('invitation.show', $oldToken))->assertNotFound();
        $this->activate($oldToken)->assertStatus(410);
        $this->assertSame(0, User::where('member_id', $member->id)->count());
        $this->activate($newToken)->assertRedirect(route('dashboard'));
        $this->assertSame(1, User::where('member_id', $member->id)->count());
    }

    public function test_invitation_is_refused_for_ineligible_members(): void
    {
        $service = app(InvitationService::class);
        $withAccount = $this->walkIn();
        User::factory()->member($withAccount)->create();
        $inactive = $this->walkIn(['status' => MemberStatus::Inactive]);
        $deleted = $this->walkIn();
        $deleted->delete();
        $sharedEmail = $this->walkIn();
        User::factory()->member()->create(['email' => $sharedEmail->email]);

        $this->assertSame('This member already has a login account.', $service->memberInvitationBlocker($withAccount));
        $this->assertStringContainsString('deactivated', $service->memberInvitationBlocker($inactive));
        $this->assertStringContainsString('deleted', $service->memberInvitationBlocker($deleted));
        $this->assertStringContainsString('Another login account already uses this email', $service->memberInvitationBlocker($sharedEmail));

        foreach ([$withAccount, $inactive, $sharedEmail] as $member) {
            $this->actingAs($this->admin)->post(route('admin.members.account-invitation', $member))->assertSessionHas('error');
        }
        $this->get(route('admin.members.show', $sharedEmail))->assertSee('Another login account already uses this email')->assertDontSee('Send activation invitation');
        $this->assertSame(0, UserInvitation::count());
    }

    public function test_only_administrators_can_send_activation_invitations(): void
    {
        $member = $this->walkIn();

        $this->post(route('admin.members.account-invitation', $member))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->member()->create())->post(route('admin.members.account-invitation', $member))->assertForbidden();
        $this->actingAs(User::factory()->member()->create())->post(route('admin.members.account-invitation', $member))->assertForbidden();
        $this->assertSame(0, UserInvitation::count());
    }

    // ---- Token security ------------------------------------------------------------------------

    public function test_expired_revoked_and_reused_tokens_are_refused(): void
    {
        $service = app(InvitationService::class);

        [, $expired] = $service->issueForMember($this->walkIn(), $this->admin);
        $this->travel(73)->hours();
        $this->get(route('invitation.show', $expired))->assertStatus(410)->assertSee('expired');
        $this->activate($expired)->assertStatus(410);

        [$inv, $revoked] = $service->issueForMember($this->walkIn(), $this->admin);
        $service->revoke($inv, $this->admin);
        $this->get(route('invitation.show', $revoked))->assertStatus(410)->assertSee('revoked');

        [, $used] = $service->issueForMember($member = $this->walkIn(), $this->admin);
        $this->activate($used)->assertRedirect(route('dashboard'));
        auth()->logout();
        $this->activate($used)->assertStatus(410);
        $this->get(route('invitation.show', $used))->assertStatus(410)->assertSee('already been used');

        $this->assertSame(1, User::members()->count());
        $this->assertSame(1, User::where('member_id', $member->id)->count());
        $this->assertSame(0, UserInvitation::whereRaw('LENGTH(token_hash) <> 64')->count()); // hashes only
        $this->assertSame(0, UserInvitation::where('token_hash', $used)->count());          // never the raw token
    }

    public function test_a_concurrent_second_acceptance_cannot_create_another_account(): void
    {
        $member = $this->walkIn();
        [, $token] = app(InvitationService::class)->issueForMember($member, $this->admin);

        // Another request activated an account for this member between our page load and submit.
        DB::table('users')->insert([
            'name' => 'Race Winner', 'email' => $member->email, 'password' => Hash::make('x'), 'role' => 'member',
            'member_id' => $member->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->activate($token)->assertStatus(410);
        $this->assertSame(1, User::where('member_id', $member->id)->count());
        $this->assertGuest();
    }

    public function test_activation_cannot_request_a_different_role_or_name(): void
    {
        [, $token] = app(InvitationService::class)->issueForMember($member = $this->walkIn(['first_name' => 'Real', 'middle_name' => null]), $this->admin);

        $this->activate($token, ['name' => 'Someone Else'])->assertSessionHasErrors('name');
        $this->assertSame(0, User::count() - 1); // only the admin exists

        $this->activate($token, ['role' => 'admin', 'is_active' => '1', 'member_id' => '999'])->assertRedirect(route('dashboard'));
        $user = User::where('member_id', $member->id)->sole();
        $this->assertSame(UserRole::Member, $user->role);
        $this->assertStringStartsWith('Real ', $user->name);
        $this->get('/admin/dashboard')->assertForbidden();
    }

    public function test_member_deactivated_or_deleted_after_invitation_cannot_activate(): void
    {
        $service = app(InvitationService::class);
        [, $t1] = $service->issueForMember($m1 = $this->walkIn(), $this->admin);
        [, $t2] = $service->issueForMember($m2 = $this->walkIn(), $this->admin);
        $m1->forceFill(['status' => MemberStatus::Inactive])->save();
        $m2->delete();

        $this->get(route('invitation.show', $t1))->assertStatus(410)->assertSee('deactivated');
        $this->activate($t1)->assertStatus(410);
        $this->activate($t2)->assertStatus(410);
        $this->assertSame(0, User::members()->count());
    }

    public function test_database_queue_mode_stores_the_activation_link_only_encrypted(): void
    {
        config(['queue.default' => 'database']);
        [, $token] = app(InvitationService::class)->issueForMember($this->walkIn(), $this->admin);

        $payload = (string) DB::table('jobs')->value('payload');
        $this->assertNotSame('', $payload);
        $this->assertStringNotContainsString($token, $payload);
        $this->assertSame(EmailDeliveryStatus::Queued, EmailDelivery::sole()->status);
    }

    // ---- Email synchronisation -------------------------------------------------------------------

    private function changeEmail(Member $member, string $email)
    {
        return $this->actingAs($this->admin)->put(route('admin.members.update', $member), [
            'first_name' => $member->first_name, 'middle_name' => $member->middle_name, 'last_name' => $member->last_name,
            'email' => $email, 'membership_category_id' => $member->membership_category_id, 'membership_level_id' => $member->membership_level_id,
        ]);
    }

    public function test_changing_the_email_revokes_pending_activation_invitations(): void
    {
        $member = $this->walkIn();
        [$invitation, $token] = app(InvitationService::class)->issueForMember($member, $this->admin);

        $this->changeEmail($member, self::email('new.address'))->assertRedirect(route('admin.members.show', $member));

        $this->assertSame(InvitationStatus::Revoked, $invitation->fresh()->status);
        $this->assertSame($this->admin->id, $invitation->fresh()->revoked_by);
        auth()->logout();
        $this->get(route('invitation.show', $token))->assertStatus(410);
        $this->activate($token)->assertStatus(410);
        $this->assertSame(0, User::members()->count());
    }

    public function test_an_invitation_bound_to_an_old_email_is_refused_even_if_still_pending(): void
    {
        $member = $this->walkIn();
        [, $token] = app(InvitationService::class)->issueForMember($member, $this->admin);
        $member->forceFill(['email' => self::email('changed.directly')])->save(); // bypassing the service

        $this->get(route('invitation.show', $token))->assertStatus(410)->assertSee('email address on this membership has changed');
        $this->activate($token)->assertStatus(410);
        $this->assertSame(0, User::members()->count());
    }

    public function test_existing_account_email_changes_only_after_confirmation_by_the_signed_in_member(): void
    {
        $member = $this->walkIn(['first_name' => 'Ella', 'middle_name' => null]);
        $account = User::factory()->member($member)->create();
        $oldEmail = $account->email;
        $newEmail = self::email('ella.new');

        $this->changeEmail($member, $newEmail)->assertSessionHas('success');

        // Account access does NOT move: still the old sign-in email.
        $this->assertSame($oldEmail, $account->fresh()->email);
        $this->assertSame($newEmail, $this->lastMail()->getTo()[0]->getAddress());        // confirmation goes ONLY to the new address
        $this->assertSame('ConfirmAccountEmailChange', EmailDelivery::latest('id')->first()->notification);
        $this->assertDatabaseHas('user_account_events', ['user_id' => $account->id, 'type' => UserAccountEvent::EMAIL_CHANGE_REQUESTED, 'old_value' => $oldEmail, 'new_value' => $newEmail]);
        $this->get(route('admin.members.show', $member))->assertSee('Sign-in email change pending');
        preg_match('#https?://[^"\s]+/account/email/confirm/[^"\s]+#', html_entity_decode((string) $this->lastMail()->getHtmlBody()), $m);
        $link = parse_url($m[0], PHP_URL_PATH).'?'.parse_url($m[0], PHP_URL_QUERY);

        auth()->logout();
        $this->post('/login', ['email' => $newEmail, 'password' => 'Password123'])->assertSessionHasErrors('email');
        $this->assertGuest();

        // Not signed in → must sign in first (with the OLD email); a different member → refused.
        $this->get($link)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->member()->create())->get($link)->assertForbidden();
        $this->actingAs($account->fresh())->get(str_replace('/confirm/', '/confirm/', preg_replace('#/[0-9a-f]{40}\?#', '/'.str_repeat('a', 40).'?', $link)))->assertForbidden();
        $this->get(route('dashboard'))->assertSee('Confirm your new email');

        $this->get($link)->assertRedirect(route('dashboard'));
        $this->assertSame($newEmail, $account->fresh()->email);
        $this->assertTrue($member->fresh()->isEmailVerified());
        $this->assertDatabaseHas('user_account_events', ['user_id' => $account->id, 'type' => UserAccountEvent::EMAIL_CHANGED, 'new_value' => $newEmail]);
        $this->actingAs($account->fresh())->get(route('dashboard'))->assertDontSee('Confirm your new email'); // a real request reloads the user

        $this->post('/logout');
        $this->post('/login', ['email' => $newEmail, 'password' => 'Password123'])->assertRedirect(route('dashboard'));
    }

    public function test_confirmation_link_for_an_outdated_email_is_refused(): void
    {
        $member = $this->walkIn();
        $account = User::factory()->member($member)->create();
        $this->changeEmail($member, self::email('first.change'));
        $first = app(MemberAccountService::class)->confirmationUrl($account->fresh(), $member->fresh());
        $this->travel(2)->minutes();
        $this->changeEmail($member->fresh(), self::email('second.change'));

        $this->actingAs($account->fresh())->get(parse_url($first, PHP_URL_PATH).'?'.parse_url($first, PHP_URL_QUERY))->assertStatus(409);
        $this->assertNotSame(self::email('first.change'), $account->fresh()->email);
    }

    public function test_confirmation_refuses_an_address_already_used_by_another_account(): void
    {
        $member = $this->walkIn();
        $account = User::factory()->member($member)->create();
        $this->changeEmail($member, self::email('taken.address'));
        User::factory()->member()->create(['email' => self::email('taken.address')]);
        $url = app(MemberAccountService::class)->confirmationUrl($account->fresh(), $member->fresh());

        $this->actingAs($account->fresh())->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))->assertStatus(409);
        $this->assertNotSame(self::email('taken.address'), $account->fresh()->email);
    }

    public function test_changing_the_email_back_restores_the_verified_state_without_a_confirmation(): void
    {
        $member = $this->walkIn();
        $account = User::factory()->member($member)->create();
        $original = $member->email;

        $this->changeEmail($member, self::email('temporary'));
        $this->changeEmail($member->fresh(), $original);

        $this->assertFalse(app(MemberAccountService::class)->emailChangePending($member->fresh(), $account->fresh()));
        $this->assertTrue($member->fresh()->isEmailVerified());
        $this->assertSame($original, $account->fresh()->email);
    }

    public function test_admin_and_member_can_resend_the_confirmation(): void
    {
        $member = $this->walkIn();
        $account = User::factory()->member($member)->create();
        $this->changeEmail($member, self::email('resend.me'));
        $before = EmailDelivery::where('notification', 'ConfirmAccountEmailChange')->count();

        $this->actingAs($this->admin)->post(route('admin.members.account-email-confirmation', $member))->assertSessionHas('success');
        $this->actingAs($account->fresh())->post(route('account.email.resend'))->assertSessionHas('success');

        $this->assertSame($before + 2, EmailDelivery::where('notification', 'ConfirmAccountEmailChange')->count());
        $this->assertSame(self::email('resend.me'), $this->lastMail()->getTo()[0]->getAddress());
    }
}
