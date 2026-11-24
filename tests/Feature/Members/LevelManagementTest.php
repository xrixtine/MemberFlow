<?php

namespace Tests\Feature\Members;

use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\MembershipLevel;
use App\Models\MembershipTransaction;
use App\Models\User;
use Database\Seeders\LevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LevelManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->seed(LevelSeeder::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Diamond',
            'fee' => '5000',
            'validity_months' => '24',
            'benefits' => "All Platinum benefits\nLifetime newsletter",
            'is_active' => '1',
            'sort_order' => '5',
        ], $overrides);
    }

    public function test_index_shows_the_four_default_levels_with_fee_validity_and_benefits(): void
    {
        $this->actingAs($this->admin)->get(route('admin.levels.index'))
            ->assertOk()
            ->assertSeeInOrder(['Basic', 'Silver', 'Gold', 'Platinum'])
            ->assertSee('₱500.00')
            ->assertSee('₱3,500.00')
            ->assertSee('12 months')
            ->assertSee('Priority registration');
    }

    public function test_admin_can_create_a_level_and_the_fee_is_stored_exactly(): void
    {
        $this->actingAs($this->admin)->get(route('admin.levels.create'))->assertOk();

        $this->actingAs($this->admin)->post(route('admin.levels.store'), $this->payload(['fee' => '4999.9']))
            ->assertRedirect(route('admin.levels.index'))
            ->assertSessionHas('success');

        $level = MembershipLevel::where('name', 'Diamond')->sole();
        $this->assertSame('4999.90', $level->fee);
        $this->assertSame(499990, $level->feeCents());
        $this->assertSame(24, $level->validity_months);
        $this->assertSame(['All Platinum benefits', 'Lifetime newsletter'], $level->benefitList());
    }

    public static function invalidFees(): array
    {
        return [
            'negative' => ['-100'],
            'thousands separator' => ['1,500'],
            'three decimals' => ['10.555'],
            'text' => ['free'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidFees')]
    public function test_invalid_fees_are_rejected(string $fee): void
    {
        $this->actingAs($this->admin)->post(route('admin.levels.store'), $this->payload(['fee' => $fee]))
            ->assertSessionHasErrors('fee');

        $this->assertDatabaseMissing('membership_levels', ['name' => 'Diamond']);
    }

    public function test_other_level_validation(): void
    {
        $this->actingAs($this->admin)->post(route('admin.levels.store'), $this->payload(['name' => 'gold']))
            ->assertSessionHasErrors(['name' => 'A level with this name already exists.']);
        $this->actingAs($this->admin)->post(route('admin.levels.store'), $this->payload(['validity_months' => '0']))
            ->assertSessionHasErrors('validity_months');
        $this->actingAs($this->admin)->post(route('admin.levels.store'), $this->payload(['validity_months' => '121']))
            ->assertSessionHasErrors('validity_months');
        $this->actingAs($this->admin)->post(route('admin.levels.store'), $this->payload(['name' => '']))
            ->assertSessionHasErrors('name');
    }

    public function test_changing_a_fee_does_not_change_existing_transactions(): void
    {
        $gold = MembershipLevel::where('name', 'Gold')->sole();
        $transaction = MembershipTransaction::factory()->for($gold, 'level')->create(['amount' => $gold->fee]);

        $this->actingAs($this->admin)
            ->put(route('admin.levels.update', $gold), $this->payload(['name' => 'Gold', 'fee' => '2750.50', 'validity_months' => '12']))
            ->assertSessionHasNoErrors();

        $this->assertSame('2750.50', $gold->fresh()->fee);
        $this->assertSame('2000.00', $transaction->fresh()->amount);
    }

    public function test_member_profile_shows_the_current_level_fee(): void
    {
        $silver = MembershipLevel::where('name', 'Silver')->sole();
        $member = Member::factory()->for($silver, 'level')->for(MembershipCategory::factory(), 'category')->create();

        $this->actingAs($this->admin)->get(route('admin.members.show', $member))->assertSee('₱1,000.00');

        $silver->update(['fee' => '1250.00']);
        $this->actingAs($this->admin)->get(route('admin.members.show', $member))->assertSee('₱1,250.00');
    }

    public function test_unused_level_can_be_deleted(): void
    {
        $this->actingAs($this->admin)->post(route('admin.levels.store'), $this->payload());
        $diamond = MembershipLevel::where('name', 'Diamond')->sole();

        $this->actingAs($this->admin)->delete(route('admin.levels.destroy', $diamond))->assertSessionHas('success');
        $this->assertModelMissing($diamond);
    }

    public function test_level_used_by_members_or_transactions_cannot_be_deleted(): void
    {
        $basic = MembershipLevel::where('name', 'Basic')->sole();
        Member::factory()->for($basic, 'level')->create();

        $silver = MembershipLevel::where('name', 'Silver')->sole();
        // Silver only appears on a historical transaction (the member has since moved to another level).
        MembershipTransaction::factory()->for($silver, 'level')->create();

        foreach ([$basic, $silver] as $level) {
            $this->actingAs($this->admin)->delete(route('admin.levels.destroy', $level))->assertSessionHas('error');
            $this->assertModelExists($level);
        }
    }

    public function test_staff_cannot_manage_levels(): void
    {
        $staff = User::factory()->member()->create();
        $gold = MembershipLevel::where('name', 'Gold')->sole();

        $this->actingAs($staff)->get(route('admin.levels.index'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.levels.store'), $this->payload())->assertForbidden();
        $this->actingAs($staff)->put(route('admin.levels.update', $gold), $this->payload(['fee' => '1']))->assertForbidden();
        $this->actingAs($staff)->delete(route('admin.levels.destroy', $gold))->assertForbidden();

        $this->assertSame('2000.00', $gold->fresh()->fee);
        $this->assertDatabaseMissing('membership_levels', ['name' => 'Diamond']);
    }
}
