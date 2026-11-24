<?php

namespace Tests\Feature\Members;

use App\Enums\UserRole;
use App\Models\Member;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Models\UserAccountEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase R2 — administrators manage member LOGIN accounts (activation itself is R3).
 */
class MemberAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_member_page_shows_whether_a_login_account_exists(): void
    {
        $without = Member::factory()->create();
        $with = Member::factory()->active()->create();
        $account = User::factory()->member($with)->create();

        $this->actingAs($this->admin)->get(route('admin.members.show', $without))->assertOk()->assertSee('No login account');
        $this->get(route('admin.members.show', $with))->assertOk()
            ->assertSee('Login account')->assertSee($account->email)->assertSee('Deactivate login account')->assertDontSee('No login account');
        $this->get(route('admin.members.index'))->assertOk()->assertSee('Login account active');
    }

    public function test_admin_deactivates_and_reactivates_a_member_account_with_audit_and_history_kept(): void
    {
        $member = Member::factory()->active()->create();
        $account = User::factory()->member($member)->create();
        $task = Task::factory()->create(['assigned_to' => $account->id]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $account->id, 'assigned_by' => $this->admin->id, 'assigned_at' => now()]);

        $this->actingAs($this->admin)->from(route('admin.members.show', $member))
            ->patch(route('admin.users.deactivate', $account))->assertRedirect(route('admin.members.show', $member));

        $this->assertFalse($account->fresh()->is_active);
        $this->assertSame($member->id, $account->fresh()->member_id);              // link kept
        $this->assertSame($account->id, $task->fresh()->assigned_to);              // task history kept
        $this->assertSame(1, TaskAssignment::where('user_id', $account->id)->count());
        $this->assertSame('active', $member->fresh()->status->value);             // membership untouched
        $this->assertDatabaseHas('user_account_events', ['user_id' => $account->id, 'type' => UserAccountEvent::DEACTIVATED, 'actor_id' => $this->admin->id]);

        $this->get(route('admin.members.show', $member))->assertSee('Reactivate login account');
        $this->patch(route('admin.users.reactivate', $account));
        $this->assertTrue($account->fresh()->is_active);
    }

    public function test_deactivated_member_is_signed_out_on_their_next_request(): void
    {
        $account = User::factory()->member()->create();
        $this->actingAs($account)->get(route('dashboard'))->assertOk();

        $this->actingAs($this->admin)->patch(route('admin.users.deactivate', $account));

        $this->actingAs($account->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_member_accounts_cannot_be_promoted_to_administrator(): void
    {
        $account = User::factory()->member()->create();

        $this->actingAs($this->admin)->get(route('admin.users.edit', $account))->assertOk()
            ->assertSee('cannot be promoted')->assertDontSee('Promote to administrator');
        $this->patch("/admin/users/{$account->id}/promote", ['confirm_email' => $account->email])->assertNotFound(); // promotion was retired (R6)

        $this->assertSame(UserRole::Member, $account->fresh()->role);
    }

    public function test_member_account_details_cannot_be_edited_from_user_management(): void
    {
        $account = User::factory()->member()->create();
        $email = $account->email;

        $this->actingAs($this->admin)->put(route('admin.users.update', $account), ['name' => 'Changed Name', 'email' => 'changed@example.test'])
            ->assertSessionHas('error');

        $this->assertSame($email, $account->fresh()->email);
    }

    public function test_last_active_administrator_protection_is_preserved(): void
    {
        $member = User::factory()->member()->create();

        $this->actingAs($this->admin)->patch(route('admin.users.deactivate', $this->admin))->assertForbidden();
        $this->assertTrue($this->admin->fresh()->is_active);
        $this->assertSame(1, User::admins()->active()->count());
        $this->assertFalse($member->can('changeStatus', $this->admin));
    }

    public function test_user_list_can_filter_member_accounts(): void
    {
        $account = User::factory()->member()->create(['name' => 'Portal Person']);
        User::factory()->admin()->create(['name' => 'Admin Person']);

        $this->actingAs($this->admin)->get(route('admin.users.index', ['role' => 'member']))->assertOk()
            ->assertSee('Portal Person')->assertDontSee('Admin Person');
    }
}
