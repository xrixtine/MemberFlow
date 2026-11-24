<?php

namespace Tests\Feature\Accounts;

use App\Enums\InvitationStatus;
use App\Enums\MemberStatus;
use App\Enums\TaskStatus;
use App\Enums\TransactionStatus;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskProgressUpdate;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Models\UserInvitation;
use App\Services\StaffRetirementService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * R6 — memberflow:retire-staff on a fixture shaped like the live database:
 * admin; staff "Lynette Scavo" (shares an email with member MF-2026-00002 "Killua Zoldyck",
 * different names on purpose); staff "Bree Van De Kamp"; a completed task held by Lynette;
 * an approved, paid membership for Killua with one voided payment.
 */
class StaffRetirementTest extends TestCase
{
    use RefreshDatabase;

    private const NO = 'MF-2026-00002';

    private User $admin;

    private User $lynette;

    private User $bree;

    private Member $killua;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 24)->setTime(9, 0));

        $this->admin = User::factory()->admin()->create(['name' => 'MemberFlow Administrator']);
        $this->killua = Member::factory()->create([
            'first_name' => 'Killua', 'middle_name' => null, 'last_name' => 'Zoldyck', 'email' => 'kei.shared@example.test',
            'status' => MemberStatus::Active, 'membership_start' => '2026-11-22', 'membership_end' => '2027-11-21',
        ]);
        $this->killua->forceFill(['membership_no' => self::NO])->save();
        Member::factory()->create()->forceFill(['membership_no' => 'MF-2026-00003'])->save();

        $this->lynette = User::factory()->staff()->create(['name' => 'Lynette Scavo', 'email' => 'kei.shared@example.test', 'password' => 'Lynette123']);
        $this->bree = User::factory()->staff()->create(['name' => 'Bree Van De Kamp', 'email' => 'bree@example.test']);
        foreach ([$this->bree, $this->lynette] as $u) {
            UserInvitation::forceCreate(['email' => $u->email, 'name' => $u->name, 'role' => 'staff', 'token_hash' => hash('sha256', $u->email),
                'status' => 'accepted', 'expires_at' => now()->subDay(), 'invited_by' => $this->admin->id, 'accepted_at' => now()->subDays(2),
                'accepted_user_id' => $u->id, 'send_count' => 1, 'last_sent_at' => now()->subDays(3)]);
            UserAccountEvent::create(['user_id' => $u->id, 'type' => UserAccountEvent::ACTIVATED, 'new_value' => 'staff']);
        }

        // Killua's money: approved application, one valid and one voided payment.
        $txn = MembershipTransaction::factory()->for($this->killua)->create(['amount' => '1000.00', 'amount_paid' => '1000.00',
            'status' => TransactionStatus::Approved, 'approved_at' => now()->subDays(2), 'period_start' => '2026-11-22', 'period_end' => '2027-11-21']);
        $txn->forceFill(['reference_no' => 'TXN-20261112-000002'])->save();
        Payment::factory()->for($txn, 'transaction')->create(['amount' => '1000.00', 'received_by' => $this->admin->id])->forceFill(['receipt_no' => 'OR-2026-000004'])->save();
        Payment::factory()->for($txn, 'transaction')->create(['amount' => '1000.00', 'received_by' => $this->admin->id])
            ->forceFill(['receipt_no' => 'OR-2026-000003', 'voided_at' => now()->subDay(), 'voided_by' => $this->admin->id, 'void_reason' => 'Wrong method'])->save();

        // Lynette's completed task, as it exists in the live data (created before R6 while she was staff).
        $this->task = Task::factory()->create(['title' => 'Sort the welcome packets', 'assigned_to' => $this->lynette->id, 'created_by' => $this->admin->id,
            'status' => TaskStatus::Completed, 'progress' => 100, 'completed_at' => '2026-11-23 10:00:00']);
        TaskAssignment::create(['task_id' => $this->task->id, 'user_id' => $this->lynette->id, 'assigned_by' => $this->admin->id, 'assigned_at' => '2026-11-22 08:00:00']);
        foreach ([['status', 'in_progress', 40], ['completed', 'completed', 100]] as [$type, $status, $progress]) {
            TaskProgressUpdate::create(['task_id' => $this->task->id, 'user_id' => $this->lynette->id, 'type' => $type, 'new_status' => $status, 'new_progress' => $progress]);
        }
    }

    private function args(array $extra = []): array
    {
        return ['--link' => [$this->lynette->id.':'.self::NO], '--retire' => [$this->bree->id], '--allow-name-mismatch' => [$this->lynette->id]] + $extra;
    }

    /** Everything R6 must never touch: one fingerprint over the business tables. */
    private function untouched(): string
    {
        $rows = [];
        foreach (['members', 'membership_transactions', 'payments', 'tasks', 'task_assignments', 'task_progress_updates', 'user_invitations'] as $t) {
            $rows[$t] = DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }
        $rows['users'] = DB::table('users')->orderBy('id')->get(['id', 'name', 'email', 'password', 'is_active', 'email_verified_at', 'invited_by', 'remember_token'])->map(fn ($r) => (array) $r)->all();

        return hash('sha256', json_encode($rows));
    }

    // ---- Dry run ---------------------------------------------------------------------------

    public function test_dry_run_is_the_default_and_changes_nothing(): void
    {
        $before = $this->untouched();
        $events = UserAccountEvent::count();

        $this->artisan('memberflow:retire-staff', $this->args())
            ->expectsOutputToContain('dry run — nothing will change')
            ->expectsOutputToContain('DRY RUN — nothing was changed. Re-run with --execute to apply these 2 change(s).')
            ->doesntExpectOutputToContain('Lynette123')
            ->assertSuccessful();

        $this->assertSame(UserRole::Staff, $this->lynette->fresh()->role);
        $this->assertSame(UserRole::Staff, $this->bree->fresh()->role);
        $this->assertNull($this->lynette->fresh()->member_id);
        $this->assertSame($events, UserAccountEvent::count());
        $this->assertSame($before, $this->untouched());
    }

    public function test_dry_run_output_masks_emails_and_never_prints_credentials(): void
    {
        $hash = $this->lynette->getRawOriginal('password');

        $this->artisan('memberflow:retire-staff', $this->args())
            ->expectsOutputToContain('kei***@example.test')
            ->doesntExpectOutputToContain('kei.shared@example.test')
            ->doesntExpectOutputToContain($hash)
            ->assertSuccessful();

        // (Each output line satisfies one expectation, so the note on the same row is checked separately.)
        $this->artisan('memberflow:retire-staff', $this->args())->expectsOutputToContain('names differ (confirmed)')->assertSuccessful();
    }

    // ---- Execution ----------------------------------------------------------------------------

    public function test_execute_links_lynette_retires_bree_and_preserves_everything_else(): void
    {
        $before = $this->untouched();
        $ids = [$this->admin->id, $this->lynette->id, $this->bree->id];
        $hash = $this->lynette->getRawOriginal('password');

        $this->artisan('memberflow:retire-staff', $this->args(['--execute' => true]))
            ->expectsOutputToContain('2 change(s) applied and recorded in the account audit trail.')->assertSuccessful();

        $lynette = $this->lynette->fresh();
        $this->assertSame(UserRole::Member, $lynette->role);
        $this->assertSame($this->killua->id, $lynette->member_id);
        $this->assertSame($hash, $lynette->getRawOriginal('password'));        // password hash untouched
        $this->assertSame('Lynette Scavo', $lynette->name);                    // not renamed
        $this->assertSame('Killua Zoldyck', $this->killua->fresh()->fullName()); // not renamed
        $this->assertSame(UserRole::LegacyStaff, $this->bree->fresh()->role);
        $this->assertSame(UserRole::Admin, $this->admin->fresh()->role);
        $this->assertSame($ids, User::orderBy('id')->pluck('id')->all());     // no user created or deleted
        $this->assertSame(2, Member::count());                                 // no member created

        // Only users.role / users.member_id changed: every business row is byte-identical.
        $this->assertSame($before, $this->untouched());
    }

    public function test_every_change_is_audited_and_history_is_kept(): void
    {
        $this->artisan('memberflow:retire-staff', $this->args(['--execute' => true]))->assertSuccessful();

        $link = UserAccountEvent::where('user_id', $this->lynette->id)->where('type', UserAccountEvent::ROLE_CHANGED)->sole();
        $this->assertSame(['staff', 'member'], [$link->old_value, $link->new_value]);
        $this->assertStringStartsWith(StaffRetirementService::TAG_LINK, $link->note);
        $this->assertStringContainsString(self::NO, $link->note);
        $this->assertStringContainsString('Different names confirmed', $link->note);
        $retire = UserAccountEvent::where('user_id', $this->bree->id)->where('type', UserAccountEvent::ROLE_CHANGED)->sole();
        $this->assertSame(['staff', 'legacy_staff'], [$retire->old_value, $retire->new_value]);

        // Earlier history (activation as staff, accepted staff invitations) is untouched.
        $this->assertSame('staff', UserAccountEvent::where('user_id', $this->lynette->id)->where('type', UserAccountEvent::ACTIVATED)->value('new_value'));
        $this->assertSame(2, UserInvitation::where('role', 'staff')->where('status', InvitationStatus::Accepted->value)->count());
        $this->actingAs($this->admin)->get(route('admin.users.edit', $this->lynette))->assertOk()->assertSee('Role changed')->assertSee('Account activated');
    }

    public function test_running_it_again_is_a_no_op(): void
    {
        $this->artisan('memberflow:retire-staff', $this->args(['--execute' => true]))->assertSuccessful();
        $events = UserAccountEvent::count();

        $this->artisan('memberflow:retire-staff', $this->args(['--execute' => true]))
            ->expectsOutputToContain('Nothing to do')->assertSuccessful();
        $this->artisan('memberflow:retire-staff', $this->args())->expectsOutputToContain('already done')->assertSuccessful();

        $this->assertSame($events, UserAccountEvent::count());
    }

    // ---- Refusals -------------------------------------------------------------------------------

    public function test_the_name_mismatch_needs_the_explicit_override_for_that_user_only(): void
    {
        $args = $this->args();
        unset($args['--allow-name-mismatch']);
        $this->artisan('memberflow:retire-staff', $args + ['--execute' => true])
            ->expectsOutputToContain('is named "Lynette Scavo" but member '.self::NO.' is "Killua Zoldyck"')->assertFailed();

        // The override names another account: refused, and Lynette's mismatch is still unconfirmed.
        $this->artisan('memberflow:retire-staff', $args + ['--allow-name-mismatch' => [$this->bree->id], '--execute' => true])
            ->expectsOutputToContain('--allow-name-mismatch='.$this->bree->id.' is only valid for an account that is being linked')->assertFailed();

        $this->assertSame(UserRole::Staff, $this->lynette->fresh()->role);
        $this->assertSame(UserRole::Staff, $this->bree->fresh()->role);   // all-or-nothing
    }

    public function test_unauthorized_or_unsafe_mappings_are_refused(): void
    {
        $service = app(StaffRetirementService::class);
        $plan = fn (array $links, array $retire = [], array $allow = []) => $service->plan($links, $retire, $allow)['errors'];
        $L = $this->lynette->id;
        $B = $this->bree->id;

        // Wrong member: email does not match (no override exists for that).
        $this->assertStringContainsString('email does not match', implode(' ', $plan([$L => 'MF-2026-00003'], [$B], [$L])));
        // Unknown records.
        $this->assertStringContainsString('does not exist', implode(' ', $plan([$L => 'MF-2026-99999'], [$B], [$L])));
        $this->assertStringContainsString('User #999 does not exist', implode(' ', $plan([999 => self::NO, $L => self::NO], [$B], [$L])));
        // Every staff account must be covered.
        $this->assertStringContainsString("not covered by --link or --retire: {$B}", implode(' ', $plan([$L => self::NO], [], [$L])));
        // An account listed twice.
        $this->assertStringContainsString('both to link and to retire', implode(' ', $plan([$L => self::NO], [$B, $L], [$L])));
        // Administrators are never touched.
        $this->assertStringContainsString('not staff', implode(' ', $plan([$L => self::NO], [$B, $this->admin->id], [$L])));

        // Member already has a login account.
        $other = User::factory()->member($this->killua)->create(['email' => 'other.login@example.test']);
        $this->assertStringContainsString("already has a login account (user #{$other->id})", implode(' ', $plan([$L => self::NO], [$B], [$L])));
        $other->forceFill(['role' => UserRole::Admin, 'member_id' => null])->save();

        // Deactivated or deleted member.
        $this->killua->forceFill(['status' => MemberStatus::Inactive])->save();
        $this->assertStringContainsString('is deactivated', implode(' ', $plan([$L => self::NO], [$B], [$L])));
        $this->killua->forceFill(['status' => MemberStatus::Active])->save();

        // A pending invitation for that member.
        UserInvitation::forceCreate(['email' => $this->killua->email, 'name' => 'x', 'role' => 'member', 'member_id' => $this->killua->id,
            'token_hash' => hash('sha256', 'pending'), 'status' => 'pending', 'expires_at' => now()->addDay(), 'invited_by' => $this->admin->id, 'send_count' => 1]);
        $this->assertStringContainsString('pending invitation', implode(' ', $plan([$L => self::NO], [$B], [$L])));
        UserInvitation::where('status', 'pending')->update(['status' => 'revoked']);

        // Bree cannot be retired while she holds open work.
        $open = Task::factory()->create(['assigned_to' => $B, 'status' => TaskStatus::InProgress]);
        $this->assertStringContainsString('still holds 1 open task', implode(' ', $plan([$L => self::NO], [$B], [$L])));
        $open->forceFill(['status' => TaskStatus::Completed, 'progress' => 100])->save();

        $this->assertSame([], $plan([$L => self::NO], [$B], [$L]));   // the approved mapping itself is valid
        $this->assertSame(UserRole::Staff, $this->lynette->fresh()->role);
    }

    // ---- After the migration ------------------------------------------------------------------

    public function test_lynette_uses_the_member_portal_for_mf_2026_00002_only(): void
    {
        $this->artisan('memberflow:retire-staff', $this->args(['--execute' => true]))->assertSuccessful();
        $other = Member::where('membership_no', 'MF-2026-00003')->sole();

        $this->post('/login', ['email' => 'kei.shared@example.test', 'password' => 'Lynette123'])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk()->assertSee('Welcome, Killua.')->assertSee(self::NO)->assertSee('Valid until Nov 21, 2027');
        $this->get(route('my.membership'))->assertOk()->assertSee('TXN-20261112-000002')->assertSee('Killua Zoldyck');
        $this->get(route('my.payments.index'))->assertOk()->assertSee('OR-2026-000004')->assertSee('OR-2026-000003')->assertSee('Void');
        $this->get(route('my.payments.receipt', Payment::where('receipt_no', 'OR-2026-000004')->sole()))->assertOk();

        // The historical completed task stays completed and visible.
        $this->get(route('my.tasks.index'))->assertOk()->assertSee('Sort the welcome packets');
        $this->get(route('my.tasks.show', $this->task))->assertOk()->assertSee('Only an administrator can reopen it');
        $this->get(route('my.history'))->assertOk()->assertSee('Sort the welcome packets');
        $task = $this->task->fresh();
        $this->assertSame([TaskStatus::Completed, 100, '2026-11-23 10:00:00', $this->admin->id], [$task->status, $task->progress, $task->completed_at->toDateTimeString(), $task->created_by]);
        $this->assertSame('2026-11-22 08:00:00', TaskAssignment::where('task_id', $task->id)->sole()->assigned_at->toDateTimeString());
        $this->assertSame(2, TaskProgressUpdate::where('task_id', $task->id)->count());

        // Isolation and no administrator powers.
        $this->get(route('admin.members.show', $other))->assertForbidden();
        $this->get(route('admin.dashboard'))->assertForbidden();
        $this->patch(route('admin.transactions.approve', MembershipTransaction::sole()))->assertForbidden();
        $this->post(route('admin.tasks.store'), ['title' => 'x', 'priority' => 'low', 'assigned_to' => $this->lynette->id])->assertForbidden();
        $this->patch(route('admin.tasks.reassign', $this->task), ['assigned_to' => $this->lynette->id])->assertForbidden();
        $this->post(route('admin.payments.store'), ['membership_transaction_id' => 1, 'amount' => '1.00'])->assertForbidden();
    }

    public function test_bree_can_no_longer_sign_in_and_her_session_ends(): void
    {
        $this->actingAs($this->bree)->get('/profile')->assertRedirect(route('login'));   // staff already cannot sign in (R6 code)
        $this->artisan('memberflow:retire-staff', $this->args(['--execute' => true]))->assertSuccessful();

        $this->post('/login', ['email' => 'bree@example.test', 'password' => 'Password123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($this->bree->fresh())->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->actingAs($this->admin)->get(route('admin.users.edit', $this->bree))->assertOk()->assertSee('Retired staff account');
    }

    public function test_new_tasks_reach_lynette_only_while_her_membership_is_active(): void
    {
        $this->artisan('memberflow:retire-staff', $this->args(['--execute' => true]))->assertSuccessful();
        $tasks = app(TaskService::class);

        $this->assertNull($tasks->assignmentBlocker($this->lynette->fresh()));
        $tasks->create(['title' => 'First member task', 'priority' => 'low'], $this->lynette->fresh(), $this->admin);

        $this->killua->forceFill(['status' => MemberStatus::Expired, 'membership_end' => today()->subDay()])->save();
        $this->assertNotNull($tasks->assignmentBlocker($this->lynette->fresh()));
        $this->actingAs($this->admin)->post(route('admin.tasks.store'), ['title' => 'Refused', 'priority' => 'low', 'assigned_to' => $this->lynette->id])
            ->assertSessionHasErrors('assigned_to');
        $this->assertDatabaseMissing('tasks', ['title' => 'Refused']);
    }

    // ---- Reversal -----------------------------------------------------------------------------------

    public function test_revert_dry_run_then_execute_restores_only_the_migrated_fields(): void
    {
        $before = $this->untouched();
        $this->artisan('memberflow:retire-staff', $this->args(['--execute' => true]))->assertSuccessful();

        // Work done after the migration is kept by the reversal.
        $newTask = app(TaskService::class)->create(['title' => 'After migration', 'priority' => 'low'], $this->lynette->fresh(), $this->admin);

        $revert = ['--link' => [$this->lynette->id.':'.self::NO], '--retire' => [$this->bree->id], '--revert' => true];
        $this->artisan('memberflow:retire-staff', $revert)->expectsOutputToContain('DRY RUN')->assertSuccessful();
        $this->assertSame(UserRole::Member, $this->lynette->fresh()->role);

        $this->artisan('memberflow:retire-staff', $revert + ['--execute' => true])->expectsOutputToContain('2 change(s) applied')->assertSuccessful();

        $this->assertSame(UserRole::Staff, $this->lynette->fresh()->role);
        $this->assertNull($this->lynette->fresh()->member_id);
        $this->assertSame(UserRole::Staff, $this->bree->fresh()->role);
        $this->assertTrue($newTask->fresh()->exists);                                        // later records kept
        $this->assertSame(4, UserAccountEvent::where('type', UserAccountEvent::ROLE_CHANGED)->count()); // migration + reversal audited

        $newTask->assignments()->delete();
        $newTask->updates()->delete();
        $newTask->forceDelete();
        $this->assertSame($before, $this->untouched());                                    // back to the original state

        // Reverting again changes nothing.
        $this->artisan('memberflow:retire-staff', $revert + ['--execute' => true])->expectsOutputToContain('Nothing to do')->assertSuccessful();
    }

    public function test_revert_refuses_to_overwrite_later_legitimate_changes(): void
    {
        $this->artisan('memberflow:retire-staff', $this->args(['--execute' => true]))->assertSuccessful();

        // Afterwards an administrator deliberately changes Lynette's account (simulated later role change).
        $this->lynette->fresh()->forceFill(['role' => UserRole::Admin, 'member_id' => null])->save();
        UserAccountEvent::create(['user_id' => $this->lynette->id, 'type' => UserAccountEvent::ROLE_CHANGED, 'old_value' => 'member', 'new_value' => 'admin', 'note' => 'Later manual change.']);

        $this->artisan('memberflow:retire-staff', ['--link' => [$this->lynette->id.':'.self::NO], '--retire' => [$this->bree->id], '--revert' => true, '--execute' => true])
            ->expectsOutputToContain('Manual intervention required')->assertFailed();

        $this->assertSame(UserRole::Admin, $this->lynette->fresh()->role);            // not overwritten
        $this->assertSame(UserRole::LegacyStaff, $this->bree->fresh()->role);          // all-or-nothing
    }

    // ---- Administrators ------------------------------------------------------------------------------

    public function test_create_admin_command_asks_for_a_hidden_password_and_audits(): void
    {
        $this->artisan('memberflow:create-admin', ['--name' => 'Second Admin', '--email' => 'Second.Admin@Example.test'])
            ->expectsQuestion('Password (hidden; at least 8 characters with upper- and lower-case letters and a number)', 'weak')
            ->expectsQuestion('Confirm password', 'weak')
            ->assertFailed();
        $this->assertDatabaseMissing('users', ['email' => 'second.admin@example.test']);

        $this->artisan('memberflow:create-admin', ['--name' => 'Second Admin', '--email' => 'Second.Admin@Example.test'])
            ->expectsQuestion('Password (hidden; at least 8 characters with upper- and lower-case letters and a number)', 'StrongPass123')
            ->expectsQuestion('Confirm password', 'StrongPass123')
            ->doesntExpectOutputToContain('StrongPass123')
            ->assertSuccessful();

        $admin = User::where('email', 'second.admin@example.test')->sole();
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertNull($admin->member_id);
        $this->assertTrue(Hash::check('StrongPass123', $admin->password));
        $this->assertDatabaseHas('user_account_events', ['user_id' => $admin->id, 'type' => UserAccountEvent::ADMIN_CREATED]);

        $this->artisan('memberflow:create-admin', ['--name' => 'Dup', '--email' => 'second.admin@example.test'])
            ->expectsQuestion('Password (hidden; at least 8 characters with upper- and lower-case letters and a number)', 'StrongPass123')
            ->expectsQuestion('Confirm password', 'StrongPass123')
            ->assertFailed();
    }
}
