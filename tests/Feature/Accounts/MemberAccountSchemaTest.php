<?php

namespace Tests\Feature\Accounts;

use App\Enums\InvitationStatus;
use App\Enums\UserRole;
use App\Exceptions\MembershipException;
use App\Models\Member;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\MembershipService;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Revision R1 — member accounts at the schema and domain level.
 *
 * Every rule here is enforced by MariaDB itself (foreign keys, UNIQUE, CHECK),
 * so it holds even if application code is bypassed.
 */
class MemberAccountSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function constraintRows(string $table): array
    {
        return DB::select(
            'SELECT constraint_name AS name, check_clause AS clause FROM information_schema.check_constraints
             WHERE constraint_schema = DATABASE() AND table_name = ? ORDER BY constraint_name',
            [$table],
        );
    }

    private function foreignKey(string $table, string $column): ?object
    {
        return DB::selectOne(
            'SELECT k.referenced_table_name AS ref_table, k.referenced_column_name AS ref_column, r.delete_rule, r.update_rule
             FROM information_schema.key_column_usage k
             JOIN information_schema.referential_constraints r
               ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
             WHERE k.table_schema = DATABASE() AND k.table_name = ? AND k.column_name = ? AND k.referenced_table_name IS NOT NULL',
            [$table, $column],
        );
    }

    /** Raw insert, bypassing models, to prove the database itself enforces the rule. */
    private function insertUser(array $overrides): void
    {
        DB::table('users')->insert($overrides + [
            'name' => 'Raw User',
            'email' => Str::random(12).'@example.test',
            'password' => 'not-a-real-hash',
            'role' => 'staff',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertInvitation(array $overrides): void
    {
        DB::table('user_invitations')->insert($overrides + [
            'email' => Str::random(12).'@example.test',
            'name' => 'Raw Invitee',
            'role' => 'staff',
            'token_hash' => hash('sha256', Str::random(64)),
            'status' => 'pending',
            'expires_at' => now()->addDay(),
            'invited_by' => User::factory()->admin()->create()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---- Structure ---------------------------------------------------------

    public function test_member_id_columns_and_foreign_keys_exist_with_restrict_rules(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'member_id'));
        $this->assertTrue(Schema::hasColumn('user_invitations', 'member_id'));

        foreach (['users', 'user_invitations'] as $table) {
            $fk = $this->foreignKey($table, 'member_id');
            $this->assertNotNull($fk, "{$table}.member_id has no foreign key");
            $this->assertSame(['members', 'id', 'RESTRICT', 'RESTRICT'], [$fk->ref_table, $fk->ref_column, $fk->delete_rule, $fk->update_rule]);
        }

        $nullable = DB::table('information_schema.columns')->where('table_schema', DB::raw('DATABASE()'))
            ->where('column_name', 'member_id')->whereIn('table_name', ['users', 'user_invitations'])->pluck('is_nullable')->unique()->all();
        $this->assertSame(['YES'], $nullable);
    }

    public function test_users_member_id_has_a_unique_index_and_check_constraints_exist(): void
    {
        $unique = DB::select("SHOW INDEX FROM users WHERE Column_name = 'member_id' AND Non_unique = 0");
        $this->assertCount(1, $unique);

        $this->assertSame(['users_member_link_matches_role', 'users_role_valid'], array_column($this->constraintRows('users'), 'name'));
        $this->assertSame(
            ['user_invitations_member_link_matches_role', 'user_invitations_role_valid', 'user_invitations_staff_have_inviter'],
            array_column($this->constraintRows('user_invitations'), 'name'),
        );
    }

    // ---- Relationships -------------------------------------------------------

    public function test_member_account_relationship_works_in_both_directions(): void
    {
        $member = Member::factory()->active()->create();
        $user = User::factory()->member($member)->create();

        $this->assertTrue($user->isMember());
        $this->assertSame(UserRole::Member, $user->fresh()->role);
        $this->assertTrue($user->member->is($member));
        $this->assertTrue($member->account->is($user));
        $this->assertTrue($member->hasAccount());
        $this->assertSame($member->email, $user->email);
        $this->assertSame(1, User::members()->count());
    }

    public function test_member_relationship_survives_soft_delete_for_history(): void
    {
        $member = Member::factory()->create();
        $user = User::factory()->member($member)->create();
        // Bypass the service guard on purpose: the relationship must still resolve for history.
        $member->delete();

        $this->assertTrue($user->fresh()->member->is($member));
        $this->assertTrue($user->fresh()->member->trashed());
    }

    public function test_member_invitation_relationships(): void
    {
        $member = Member::factory()->create();
        $this->insertInvitation(['role' => 'member', 'member_id' => $member->id, 'email' => $member->email]);

        $invitation = UserInvitation::sole();
        $this->assertSame(UserRole::Member, $invitation->role);
        $this->assertTrue($invitation->member->is($member));
        $this->assertTrue($member->accountInvitations->first()->is($invitation));
        $this->assertSame(InvitationStatus::Pending, $invitation->status);
    }

    public function test_member_id_is_not_mass_assignable(): void
    {
        $this->assertNotContains('member_id', (new User)->getFillable());
        $this->assertNotContains('role', (new User)->getFillable());
        $this->assertNotContains('member_id', (new UserInvitation)->getFillable());

        // The app refuses (rather than silently dropping) mass-assigned guarded attributes.
        $this->expectException(MassAssignmentException::class);
        new User(['name' => 'X', 'email' => 'x@example.test', 'password' => 'Password123', 'member_id' => 1]);
    }

    // ---- Uniqueness ------------------------------------------------------------

    public function test_one_member_record_cannot_have_two_accounts(): void
    {
        $member = Member::factory()->create();
        User::factory()->member($member)->create();

        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->member($member)->create(['email' => 'second.account@example.test']);
    }

    public function test_a_member_may_have_several_invitations_over_time(): void
    {
        $member = Member::factory()->create();
        $this->insertInvitation(['role' => 'member', 'member_id' => $member->id, 'status' => 'revoked']);
        $this->insertInvitation(['role' => 'member', 'member_id' => $member->id, 'status' => 'pending']);

        $this->assertSame(2, $member->accountInvitations()->count());
    }

    // ---- Invalid member-account assignments --------------------------------------

    public function test_member_role_without_member_record_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->insertUser(['role' => 'member', 'member_id' => null]);
    }

    public function test_non_member_roles_cannot_be_linked_to_a_member(): void
    {
        $member = Member::factory()->create();

        foreach (['admin', 'staff', 'legacy_staff'] as $role) {
            try {
                $this->insertUser(['role' => $role, 'member_id' => $member->id]);
                $this->fail("A {$role} account was linked to a member record");
            } catch (QueryException $e) {
                $this->assertStringContainsString('users_member_link_matches_role', $e->getMessage());
            }
        }
        $this->assertSame(0, User::whereNotNull('member_id')->count());
    }

    public function test_changing_a_linked_member_account_to_another_role_without_unlinking_is_rejected(): void
    {
        $user = User::factory()->member()->create();

        $this->expectException(QueryException::class);
        $user->forceFill(['role' => UserRole::Admin])->save();
    }

    public function test_unknown_role_values_are_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->insertUser(['role' => 'superuser']);
    }

    public function test_account_cannot_point_to_a_missing_member(): void
    {
        $this->expectException(QueryException::class);
        $this->insertUser(['role' => 'member', 'member_id' => 999999]);
    }

    public function test_invitation_rules_are_enforced_by_the_database(): void
    {
        $member = Member::factory()->create();
        $cases = [
            'member invitation without member' => ['role' => 'member', 'member_id' => null],
            'staff invitation linked to member' => ['role' => 'staff', 'member_id' => $member->id],
            'administrator invitation' => ['role' => 'admin', 'member_id' => null],
            'retired role invitation' => ['role' => 'legacy_staff', 'member_id' => null],
            'missing member' => ['role' => 'member', 'member_id' => 999999],
        ];
        foreach ($cases as $label => $row) {
            try {
                $this->insertInvitation($row);
                $this->fail("Accepted: {$label}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, UserInvitation::count());
    }

    // ---- Deletion protection -------------------------------------------------------

    public function test_member_with_an_account_cannot_be_hard_deleted(): void
    {
        $member = Member::factory()->create();
        User::factory()->member($member)->create();

        $this->expectException(QueryException::class);
        $member->forceDelete();
    }

    public function test_member_with_an_invitation_cannot_be_hard_deleted(): void
    {
        $member = Member::factory()->create();
        $this->insertInvitation(['role' => 'member', 'member_id' => $member->id]);

        $this->expectException(QueryException::class);
        $member->forceDelete();
    }

    public function test_member_with_an_account_cannot_be_soft_deleted_through_the_service(): void
    {
        $member = Member::factory()->create();
        User::factory()->member($member)->create();

        try {
            app(MembershipService::class)->delete($member);
            $this->fail('A member with a login account was deleted');
        } catch (MembershipException $e) {
            $this->assertStringContainsString('login account', $e->getMessage());
        }
        $this->assertFalse($member->fresh()->trashed());
    }

    public function test_member_without_account_or_transactions_can_still_be_soft_deleted(): void
    {
        $member = Member::factory()->create();
        app(MembershipService::class)->delete($member);

        $this->assertTrue($member->fresh()->trashed());
    }

    // ---- Transitional compatibility -----------------------------------------------------

    public function test_existing_style_accounts_remain_valid(): void
    {
        // Same shape as the rows already in the main database: admin/staff, no member link,
        // and accepted staff invitations without a member link.
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $this->insertInvitation(['role' => 'staff', 'member_id' => null, 'status' => 'accepted', 'accepted_user_id' => $staff->id]);

        $this->assertNull($admin->fresh()->member_id);
        $this->assertNull($staff->fresh()->member_id);
        $this->assertSame(1, UserInvitation::where('role', 'staff')->whereNull('member_id')->count());
    }

    public function test_staff_account_can_become_a_member_account_in_one_update(): void
    {
        // The shape of the future R6 conversion (role and link change together, nothing else).
        $staff = User::factory()->staff()->create();
        $member = Member::factory()->active()->create();

        $staff->forceFill(['role' => UserRole::Member, 'member_id' => $member->id])->save();

        $this->assertTrue($staff->fresh()->member->is($member));
    }

    public function test_retired_staff_value_is_storable_without_a_member_link(): void
    {
        $retired = User::factory()->legacyStaff()->create();

        $this->assertTrue($retired->fresh()->isLegacyStaff());
        $this->assertNull($retired->fresh()->member_id);
    }
}
