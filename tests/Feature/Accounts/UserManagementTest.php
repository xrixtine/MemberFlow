<?php

namespace Tests\Feature\Accounts;

use App\Enums\UserRole;
use App\Exceptions\AccountException;
use App\Models\Member;
use App\Models\Task;
use App\Models\TaskProgressUpdate;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Services\InvitationService;
use App\Services\TaskService;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['name' => 'Admin Alvarez', 'email' => 'admin@example.test']);
    }

    /** A member account created the approved way: activation invitation + acceptance. */
    private function invitedMember(string $first, string $last, string $email): User
    {
        $member = Member::factory()->active()->create(['first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'email' => $email]);
        $service = app(InvitationService::class);
        [, $token] = $service->issueForMember($member, $this->admin);

        return $service->accept($token, null, 'Activate123');
    }

    public function test_directory_lists_users_with_activation_info_search_and_filters(): void
    {
        $ana = $this->invitedMember('Ana', 'Santos', 'ana@example.test');
        $old = $this->invitedMember('Old', 'Timer', 'old@example.test');
        $old->forceFill(['is_active' => false])->save();

        $this->actingAs($this->admin)->get(route('admin.users.index'))
            ->assertOk()->assertSee('Ana Santos')->assertSee('Invitation accepted')->assertSee('Initial account (seeded)');

        $ids = fn (array $q) => $this->actingAs($this->admin)->get(route('admin.users.index', $q))->viewData('users')->pluck('id')->all();
        $this->assertSame([$ana->id], $ids(['q' => 'santos']));
        $this->assertSame([$this->admin->id], $ids(['role' => 'admin']));
        $this->assertEqualsCanonicalizing([$ana->id, $old->id], $ids(['role' => 'member']));
        $this->assertSame([$old->id], $ids(['status' => 'inactive']));
        $this->assertEqualsCanonicalizing([$ana->id, $this->admin->id], $ids(['status' => 'active']));
    }

    public function test_admin_edits_another_administrators_details_and_the_change_is_audited(): void
    {
        $other = User::factory()->admin()->create(['name' => 'Ana Santos', 'email' => 'ana@example.test']);
        $member = $this->invitedMember('Ben', 'Cruz', 'ben@example.test');

        $this->actingAs($this->admin)->put(route('admin.users.update', $other), ['name' => 'Ana S. Reyes', 'email' => $member->email])
            ->assertSessionHasErrors('email');
        $this->actingAs($this->admin)->put(route('admin.users.update', $other), ['name' => 'Ana S. Reyes', 'email' => 'ANA.REYES@example.test', 'role' => 'member', 'is_active' => 0])
            ->assertSessionHas('success');

        $other->refresh();
        $this->assertSame('Ana S. Reyes', $other->name);
        $this->assertSame('ana.reyes@example.test', $other->email);
        $this->assertTrue($other->isAdmin());            // role/is_active in the request are ignored
        $this->assertTrue($other->is_active);
        $event = UserAccountEvent::where('user_id', $other->id)->where('type', UserAccountEvent::DETAILS_UPDATED)->sole();
        $this->assertTrue($event->actor->is($this->admin));
        $this->assertStringContainsString('ana@example.test → ana.reyes@example.test', $event->note);
    }

    public function test_deactivation_blocks_sign_in_but_preserves_task_history(): void
    {
        $ana = $this->invitedMember('Ana', 'Santos', 'ana@example.test');
        $task = app(TaskService::class)->create(['title' => 'Check files', 'priority' => 'medium'], $ana, $this->admin);
        app(TaskService::class)->recordProgress($task, $ana, ['status' => 'in_progress', 'progress' => 40, 'note' => 'Halfway']);

        $this->actingAs($this->admin)->patch(route('admin.users.deactivate', $ana))
            ->assertSessionHas('success')
            ->assertSessionHas('warning', fn ($m) => str_contains($m, '1 open task'));

        $ana->refresh();
        $this->assertFalse($ana->is_active);
        $this->assertSame(UserAccountEvent::DEACTIVATED, $ana->accountEvents()->first()->type);

        // History untouched.
        $this->assertSame($ana->id, $task->fresh()->assigned_to);
        $this->assertSame(1, TaskProgressUpdate::where('user_id', $ana->id)->count());

        // Cannot sign in, cannot receive new tasks.
        auth()->logout();
        $this->post(route('login.store'), ['email' => $ana->email, 'password' => 'Activate123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($this->admin)->get(route('admin.tasks.create'))->assertDontSee('Ana Santos');

        // Reactivation restores access.
        $this->actingAs($this->admin)->patch(route('admin.users.reactivate', $ana))->assertSessionHas('success');
        $this->assertTrue($ana->fresh()->is_active);
        auth()->logout();
        $this->post(route('login.store'), ['email' => $ana->email, 'password' => 'Activate123'])->assertRedirect(route('dashboard'));
    }

    public function test_admin_cannot_deactivate_themselves_or_the_last_active_admin(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.users.deactivate', $this->admin))->assertForbidden();
        $this->assertTrue($this->admin->fresh()->is_active);

        // Service-level guard: deactivating the only active administrator is refused.
        $formerAdmin = User::factory()->admin()->inactive()->create();
        $this->expectException(AccountException::class);
        $this->expectExceptionMessage('The last active administrator cannot be deactivated.');
        app(UserAccountService::class)->deactivate($this->admin, $formerAdmin);
    }

    public function test_one_admin_may_deactivate_another_while_one_remains(): void
    {
        $second = User::factory()->admin()->create();

        $this->actingAs($this->admin)->patch(route('admin.users.deactivate', $second))->assertSessionHas('success');
        $this->assertFalse($second->fresh()->is_active);
        $this->assertSame(1, User::admins()->active()->count());
    }

    public function test_promotion_was_retired_and_administrators_come_only_from_the_cli(): void
    {
        $ana = $this->invitedMember('Ana', 'Santos', 'ana@example.test');

        $this->assertFalse(Route::has('admin.users.promote'));
        $this->actingAs($this->admin)->patch("/admin/users/{$ana->id}/promote", ['confirm_email' => $ana->email])->assertStatus(404);
        $this->actingAs($this->admin)->get(route('admin.users.edit', $ana))->assertOk()
            ->assertDontSee('Promote to administrator')->assertSee('memberflow:create-admin');
        $this->assertSame(UserRole::Member, $ana->fresh()->role);
        $this->assertSame(1, User::admins()->count());
    }

    public function test_members_and_guests_cannot_manage_users(): void
    {
        $ana = $this->invitedMember('Ana', 'Santos', 'ana@example.test');
        $ben = $this->invitedMember('Ben', 'Cruz', 'ben@example.test');

        $requests = [
            ['get', route('admin.users.index'), []],
            ['get', route('admin.users.edit', $ben), []],
            ['put', route('admin.users.update', $ben), ['name' => 'Hacked', 'email' => 'h@example.test']],
            ['patch', route('admin.users.deactivate', $ben), []],
            ['patch', route('admin.users.reactivate', $ben), []],
        ];

        foreach ($requests as [$method, $url, $data]) {
            $this->actingAs($ana)->{$method}($url, $data)->assertForbidden();
        }
        auth()->logout();
        foreach ($requests as [$method, $url, $data]) {
            $this->{$method}($url, $data)->assertRedirect(route('login'));
        }

        $this->assertTrue($ana->fresh()->isMember());
        $this->assertSame('Ben Cruz', $ben->fresh()->name);
        $this->assertTrue($ben->fresh()->is_active);
    }

    public function test_there_is_no_user_create_or_delete_route(): void
    {
        foreach (['admin.users.create', 'admin.users.store', 'admin.users.destroy', 'admin.users.show', 'admin.users.promote', 'admin.invitations.create', 'admin.invitations.store', 'register'] as $name) {
            $this->assertFalse(Route::has($name), $name);
        }
    }

    public function test_newly_activated_members_can_be_assigned_tasks_and_work_on_them(): void
    {
        // Invited but not yet accepted: not a user, so cannot be assigned.
        $pending = Member::factory()->active()->create(['first_name' => 'Pending', 'middle_name' => null, 'last_name' => 'Person', 'email' => 'pending@example.test']);
        app(InvitationService::class)->issueForMember($pending, $this->admin);
        $this->actingAs($this->admin)->get(route('admin.tasks.create'))->assertDontSee('Pending Person');

        $carla = $this->invitedMember('Carla', 'Cruz', 'carla@example.test');
        $this->actingAs($this->admin)->get(route('admin.tasks.create'))->assertSee('Carla Cruz');

        $this->actingAs($this->admin)->post(route('admin.tasks.store'), [
            'title' => 'Welcome task', 'priority' => 'medium', 'assigned_to' => $carla->id,
        ])->assertSessionHasNoErrors();
        $task = Task::sole();

        $this->actingAs($carla)->get(route('my.tasks.index'))->assertSee('Welcome task');
        $this->actingAs($carla)->post(route('my.tasks.progress', $task), ['status' => 'in_progress', 'progress' => 30, 'note' => 'On it'])
            ->assertSessionHas('success');

        $this->actingAs($this->admin)->get(route('admin.tasks.show', $task))->assertSee('On it')->assertSee('Carla Cruz');
    }
}
