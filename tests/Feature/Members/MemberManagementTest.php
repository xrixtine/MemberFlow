<?php

namespace Tests\Feature\Members;

use App\Enums\MemberSource;
use App\Enums\MemberStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\MembershipLevel;
use App\Models\MembershipTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MemberManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MembershipCategory $category;

    private MembershipLevel $level;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->category = MembershipCategory::factory()->create(['name' => 'Student']);
        $this->level = MembershipLevel::factory()->create([
            'name' => 'Gold', 'fee' => '2000.00', 'validity_months' => 12, 'benefits' => "Priority registration\nEvent discount",
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'middle_name' => 'Protacio',
            'last_name' => 'Dela Cruz',
            'email' => 'juan.delacruz@example.test',
            'phone' => '0917 123 4567',
            'birthdate' => '2000-05-15',
            'address' => '123 Rizal St, Manila',
            'latitude' => '14.5995',
            'longitude' => '120.9842',
            'location_consent' => '1',
            'membership_category_id' => $this->category->id,
            'membership_level_id' => $this->level->id,
            'create_application' => '1',
        ], $overrides);
    }

    // ---- Registration -----------------------------------------------------

    public function test_registration_form_lists_active_levels_with_fees_and_benefits(): void
    {
        MembershipLevel::factory()->inactive()->create(['name' => 'Retired level']);

        $this->actingAs($this->admin)->get(route('admin.members.create'))
            ->assertOk()
            ->assertSee('Register a member')
            ->assertSee('Gold — ₱2,000.00')
            ->assertSee('data-validity="12 months"', false)
            ->assertSee('Priority registration')
            ->assertDontSee('Retired level');
    }

    public function test_admin_registers_member_with_pending_application(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.members.store'), $this->payload());

        $member = Member::sole();
        $response->assertRedirect(route('admin.members.show', $member))->assertSessionHas('success');

        // Unique membership number from NumberGenerator.
        $this->assertMatchesRegularExpression('/^MF-\d{4}-\d{5}$/', $member->membership_no);
        $this->assertSame(sprintf('MF-%s-%05d', $member->created_at->format('Y'), $member->id), $member->membership_no);

        // Registration does NOT activate the membership.
        $this->assertSame(MemberStatus::Pending, $member->status);
        $this->assertNull($member->membership_start);
        $this->assertNull($member->membership_end);
        $this->assertSame(MemberSource::WalkIn, $member->source);
        $this->assertTrue($member->creator->is($this->admin));
        $this->assertSame('juan.delacruz@example.test', $member->email);

        // Pending application transaction with a fee snapshot.
        $transaction = MembershipTransaction::sole();
        $this->assertTrue($transaction->member->is($member));
        $this->assertSame(TransactionType::Application, $transaction->type);
        $this->assertSame(TransactionStatus::Pending, $transaction->status);
        $this->assertSame('2000.00', $transaction->amount);
        $this->assertSame('0.00', $transaction->amount_paid);
        $this->assertSame(12, $transaction->validity_months);
        $this->assertMatchesRegularExpression('/^TXN-\d{8}-\d{6}$/', $transaction->reference_no);
    }

    public function test_registration_can_skip_the_application(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.members.store'), $this->payload(['create_application' => '0']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Member::count());
        $this->assertSame(0, MembershipTransaction::count());
        $this->assertSame(MemberStatus::Pending, Member::sole()->status);
    }

    public function test_membership_numbers_are_unique_across_registrations(): void
    {
        foreach (range(1, 5) as $i) {
            $this->actingAs($this->admin)->post(route('admin.members.store'), $this->payload(['email' => "member{$i}@example.test"]));
        }

        $numbers = Member::pluck('membership_no');
        $this->assertCount(5, $numbers);
        $this->assertCount(5, $numbers->unique());
        $this->assertCount(5, MembershipTransaction::pluck('reference_no')->unique());
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing first name' => [['first_name' => ''], 'first_name'],
            'missing last name' => [['last_name' => ''], 'last_name'],
            'missing email' => [['email' => ''], 'email'],
            'invalid email' => [['email' => 'not-an-email'], 'email'],
            'name too long' => [['first_name' => str_repeat('a', 101)], 'first_name'],
            'bad phone' => [['phone' => 'call me maybe'], 'phone'],
            'future birthdate' => [['birthdate' => '2999-01-01'], 'birthdate'],
            'latitude out of range' => [['latitude' => '95'], 'latitude'],
            'longitude without latitude' => [['latitude' => ''], 'latitude'],
            'missing category' => [['membership_category_id' => ''], 'membership_category_id'],
            'unknown category' => [['membership_category_id' => 999999], 'membership_category_id'],
            'missing level' => [['membership_level_id' => ''], 'membership_level_id'],
            'unknown level' => [['membership_level_id' => 999999], 'membership_level_id'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_registration_validation(array $override, string $field): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.members.create'))
            ->post(route('admin.members.store'), $this->payload($override))
            ->assertRedirect(route('admin.members.create'))
            ->assertSessionHasErrors($field);

        $this->assertSame(0, Member::count());
        $this->assertSame(0, MembershipTransaction::count());
    }

    public function test_duplicate_email_is_rejected_case_insensitively_including_deleted_members(): void
    {
        Member::factory()->create(['email' => 'taken@example.test']);
        $archived = Member::factory()->create(['email' => 'archived@example.test']);
        $archived->delete();

        $this->actingAs($this->admin)->post(route('admin.members.store'), $this->payload(['email' => 'TAKEN@example.test']))
            ->assertSessionHasErrors(['email' => 'A member with this email address is already registered.']);

        $this->actingAs($this->admin)->post(route('admin.members.store'), $this->payload(['email' => 'archived@example.test']))
            ->assertSessionHasErrors('email');
    }

    public function test_inactive_category_or_level_cannot_be_chosen_for_new_members(): void
    {
        $inactiveCategory = MembershipCategory::factory()->inactive()->create();
        $inactiveLevel = MembershipLevel::factory()->inactive()->create();

        $this->actingAs($this->admin)->post(route('admin.members.store'), $this->payload(['membership_category_id' => $inactiveCategory->id]))
            ->assertSessionHasErrors('membership_category_id');
        $this->actingAs($this->admin)->post(route('admin.members.store'), $this->payload(['membership_level_id' => $inactiveLevel->id]))
            ->assertSessionHasErrors('membership_level_id');
    }

    public function test_status_and_server_fields_cannot_be_mass_assigned(): void
    {
        $this->actingAs($this->admin)->post(route('admin.members.store'), $this->payload([
            'status' => 'active', 'membership_no' => 'MF-HACK', 'membership_end' => '2099-12-31',
            'email_verified_at' => now()->toDateTimeString(), 'source' => 'online',
        ]));

        $member = Member::sole();
        $this->assertSame(MemberStatus::Pending, $member->status);
        $this->assertNotSame('MF-HACK', $member->membership_no);
        $this->assertNull($member->membership_end);
        $this->assertNull($member->email_verified_at);
        $this->assertSame(MemberSource::WalkIn, $member->source);
    }

    // ---- Directory, search and filters --------------------------------------

    public function test_directory_lists_members_with_category_and_level(): void
    {
        $member = Member::factory()->for($this->category, 'category')->for($this->level, 'level')->create(['first_name' => 'Ana', 'last_name' => 'Reyes']);

        $this->actingAs($this->admin)->get(route('admin.members.index'))
            ->assertOk()
            ->assertSee('Reyes, Ana')
            ->assertSee('Student')
            ->assertSee('Gold')
            ->assertSee(route('admin.members.show', $member));
    }

    public function test_directory_empty_states(): void
    {
        $this->actingAs($this->admin)->get(route('admin.members.index'))->assertSee('No members yet');
        $this->actingAs($this->admin)->get(route('admin.members.index', ['q' => 'nobody']))->assertSee('No members match your search');
    }

    public function test_search_by_name_number_and_email(): void
    {
        $ana = Member::factory()->create(['first_name' => 'Ana', 'last_name' => 'Reyes', 'email' => 'ana.r@example.test']);
        $ana->forceFill(['membership_no' => 'MF-2026-00123'])->save();
        Member::factory()->create(['first_name' => 'Ben', 'last_name' => 'Santos', 'email' => 'ben.s@example.test']);

        foreach (['Ana', 'Reyes', 'ana reyes', 'MF-2026-00123', 'ana.r@'] as $term) {
            $this->actingAs($this->admin)->get(route('admin.members.index', ['q' => $term]))
                ->assertSee('Reyes, Ana')
                ->assertDontSee('Santos, Ben');
        }
    }

    public function test_filter_by_category_level_status_and_source(): void
    {
        $otherCategory = MembershipCategory::factory()->create();
        $otherLevel = MembershipLevel::factory()->create();

        Member::factory()->for($this->category, 'category')->for($this->level, 'level')->active()->create(['last_name' => 'Alpha']);
        Member::factory()->for($otherCategory, 'category')->for($this->level, 'level')->create(['last_name' => 'Bravo']);
        Member::factory()->for($this->category, 'category')->for($otherLevel, 'level')->online()->create(['last_name' => 'Charlie']);

        $see = fn (array $query) => $this->actingAs($this->admin)->get(route('admin.members.index', $query));

        $see(['category' => $this->category->id])->assertSee('Alpha,')->assertSee('Charlie,')->assertDontSee('Bravo,');
        $see(['level' => $this->level->id])->assertSee('Alpha,')->assertSee('Bravo,')->assertDontSee('Charlie,');
        $see(['status' => 'active'])->assertSee('Alpha,')->assertDontSee('Bravo,')->assertDontSee('Charlie,');
        $see(['source' => 'online'])->assertSee('Charlie,')->assertDontSee('Alpha,');
        $see(['category' => $this->category->id, 'level' => $this->level->id])->assertSee('Alpha,')->assertDontSee('Charlie,');
        // Unknown filter values are ignored instead of erroring.
        $see(['status' => 'bogus', 'category' => 'abc'])->assertOk()->assertSee('Alpha,');
    }

    public function test_directory_is_paginated_and_keeps_filters(): void
    {
        Member::factory()->count(18)->for($this->category, 'category')->for($this->level, 'level')->create();

        $this->actingAs($this->admin)->get(route('admin.members.index', ['category' => $this->category->id]))
            ->assertOk()
            ->assertSee('Showing')
            ->assertSee('category='.$this->category->id.'&amp;page=2', false);

        $this->assertCount(3, $this->actingAs($this->admin)
            ->get(route('admin.members.index', ['category' => $this->category->id, 'page' => 2]))
            ->viewData('members'));
    }

    // ---- Profile and printing -------------------------------------------------

    public function test_profile_shows_details_level_fee_validity_benefits_and_history(): void
    {
        $this->actingAs($this->admin)->post(route('admin.members.store'), $this->payload());
        $member = Member::sole();

        $this->actingAs($this->admin)->get(route('admin.members.show', $member))
            ->assertOk()
            ->assertSee('Juan Protacio Dela Cruz')
            ->assertSee($member->membership_no)
            ->assertSee('₱2,000.00')
            ->assertSee('12 months')
            ->assertSee('Priority registration')
            ->assertSee('Event discount')
            ->assertSee(MembershipTransaction::sole()->reference_no)
            ->assertSee('Not yet active');
    }

    public function test_printable_record(): void
    {
        $member = Member::factory()->for($this->level, 'level')->create();

        $this->actingAs($this->admin)->get(route('admin.members.print', $member))
            ->assertOk()
            ->assertSee('Membership record')
            ->assertSee($member->fullName())
            ->assertSee('window.print()', false);
    }

    // ---- Editing --------------------------------------------------------------

    public function test_admin_can_update_member_details(): void
    {
        $member = Member::factory()->for($this->category, 'category')->for($this->level, 'level')->create();
        $newCategory = MembershipCategory::factory()->create(['name' => 'Corporate']);

        $this->actingAs($this->admin)
            ->put(route('admin.members.update', $member), $this->payload([
                'first_name' => 'Maria', 'email' => $member->email, 'membership_category_id' => $newCategory->id,
            ]))
            ->assertRedirect(route('admin.members.show', $member))
            ->assertSessionHasNoErrors();

        $member->refresh();
        $this->assertSame('Maria', $member->first_name);
        $this->assertTrue($member->category->is($newCategory));
    }

    public function test_member_keeps_a_category_that_was_later_deactivated(): void
    {
        $member = Member::factory()->for($this->category, 'category')->for($this->level, 'level')->create();
        $this->category->update(['is_active' => false]);

        $this->actingAs($this->admin)
            ->put(route('admin.members.update', $member), $this->payload(['email' => $member->email, 'first_name' => 'Still']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Still', $member->fresh()->first_name);
    }

    public function test_changing_email_resets_verification_and_must_stay_unique(): void
    {
        $other = Member::factory()->create();
        $member = Member::factory()->verified()->for($this->level, 'level')->create();

        $this->actingAs($this->admin)
            ->put(route('admin.members.update', $member), $this->payload(['email' => $other->email]))
            ->assertSessionHasErrors('email');

        $this->actingAs($this->admin)
            ->put(route('admin.members.update', $member), $this->payload(['email' => 'new.address@example.test']))
            ->assertSessionHasNoErrors();

        $this->assertNull($member->fresh()->email_verified_at);
    }

    public function test_level_change_while_pending_reprices_the_unpaid_application(): void
    {
        $this->actingAs($this->admin)->post(route('admin.members.store'), $this->payload());
        $member = Member::sole();
        $platinum = MembershipLevel::factory()->create(['name' => 'Platinum', 'fee' => '3500.00', 'validity_months' => 24]);

        $this->actingAs($this->admin)
            ->put(route('admin.members.update', $member), $this->payload(['membership_level_id' => $platinum->id]))
            ->assertSessionHasNoErrors();

        $transaction = MembershipTransaction::sole();
        $this->assertTrue($member->fresh()->level->is($platinum));
        $this->assertTrue($transaction->level->is($platinum));
        $this->assertSame('3500.00', $transaction->amount);
        $this->assertSame(24, $transaction->validity_months);
    }

    public function test_level_cannot_change_after_payment_or_for_active_members(): void
    {
        $other = MembershipLevel::factory()->create();

        $paid = Member::factory()->for($this->level, 'level')->create();
        MembershipTransaction::factory()->for($paid)->for($this->level, 'level')->create(['amount_paid' => '500.00']);

        $this->actingAs($this->admin)
            ->put(route('admin.members.update', $paid), $this->payload(['email' => $paid->email, 'membership_level_id' => $other->id]))
            ->assertSessionHasErrors('membership_level_id');
        $this->assertTrue($paid->fresh()->level->is($this->level));

        $active = Member::factory()->active()->for($this->level, 'level')->create();
        $this->actingAs($this->admin)
            ->put(route('admin.members.update', $active), $this->payload(['email' => $active->email, 'membership_level_id' => $other->id]))
            ->assertSessionHasErrors('membership_level_id');
        $this->assertTrue($active->fresh()->level->is($this->level));

        $this->actingAs($this->admin)->get(route('admin.members.edit', $active))
            ->assertOk()
            ->assertSee('changed through a renewal');
    }

    // ---- Deactivate, reactivate, delete ---------------------------------------

    public function test_deactivate_and_reactivate_restore_the_correct_status(): void
    {
        $active = Member::factory()->active()->create();
        $pending = Member::factory()->create();

        foreach ([[$active, MemberStatus::Active], [$pending, MemberStatus::Pending]] as [$member, $expected]) {
            $this->actingAs($this->admin)->patch(route('admin.members.deactivate', $member))->assertSessionHas('success');
            $this->assertSame(MemberStatus::Inactive, $member->fresh()->status);

            $this->actingAs($this->admin)->patch(route('admin.members.reactivate', $member))->assertSessionHas('success');
            $this->assertSame($expected, $member->fresh()->status);
        }

        $expired = Member::factory()->create([
            'status' => MemberStatus::Inactive, 'membership_start' => today()->subYears(2), 'membership_end' => today()->subYear(),
        ]);
        $this->actingAs($this->admin)->patch(route('admin.members.reactivate', $expired));
        $this->assertSame(MemberStatus::Expired, $expired->fresh()->status);
    }

    public function test_invalid_lifecycle_actions_show_an_error(): void
    {
        $inactive = Member::factory()->create(['status' => MemberStatus::Inactive]);
        $this->actingAs($this->admin)->patch(route('admin.members.deactivate', $inactive))->assertSessionHas('error');

        $active = Member::factory()->active()->create();
        $this->actingAs($this->admin)->patch(route('admin.members.reactivate', $active))->assertSessionHas('error');
        $this->assertSame(MemberStatus::Active, $active->fresh()->status);
    }

    public function test_member_without_transactions_can_be_deleted_softly(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($this->admin)->delete(route('admin.members.destroy', $member))
            ->assertRedirect(route('admin.members.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted($member);
        $this->actingAs($this->admin)->get(route('admin.members.show', $member))->assertNotFound();
        $this->actingAs($this->admin)->get(route('admin.members.index'))->assertDontSee($member->sortableName());
    }

    public function test_member_with_transactions_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)->post(route('admin.members.store'), $this->payload());
        $member = Member::sole();

        $this->actingAs($this->admin)
            ->from(route('admin.members.show', $member))
            ->delete(route('admin.members.destroy', $member))
            ->assertRedirect(route('admin.members.show', $member))
            ->assertSessionHas('error', 'This member has transaction history and cannot be deleted. Deactivate the member instead.');

        $this->assertNotSoftDeleted($member);
        $this->assertSame(1, MembershipTransaction::count());
    }

    // ---- Authorization ------------------------------------------------------------

    public function test_staff_and_guests_cannot_manage_members(): void
    {
        $member = Member::factory()->create();
        $staff = User::factory()->member()->create();

        $requests = [
            ['get', route('admin.members.index')],
            ['get', route('admin.members.create')],
            ['post', route('admin.members.store')],
            ['get', route('admin.members.show', $member)],
            ['get', route('admin.members.edit', $member)],
            ['put', route('admin.members.update', $member)],
            ['patch', route('admin.members.deactivate', $member)],
            ['patch', route('admin.members.reactivate', $member)],
            ['get', route('admin.members.print', $member)],
            ['delete', route('admin.members.destroy', $member)],
        ];

        foreach ($requests as [$method, $url]) {
            $this->actingAs($staff)->{$method}($url, $this->payload())->assertForbidden();
        }

        auth()->logout();
        foreach ($requests as [$method, $url]) {
            $this->{$method}($url, $this->payload())->assertRedirect(route('login'));
        }

        $this->assertSame(2, Member::count());   // the record under test + the signed-in member's own record
        $this->assertNotSoftDeleted($member);
    }
}
